<?php

namespace App\Domains\AI\Adapters;

use App\Domains\AI\Contracts\ContributesDiagnostics;
use App\Domains\AI\Contracts\SupportsChat;
use App\Domains\AI\Contracts\SupportsEmbeddings;
use App\Domains\AI\Contracts\SupportsImageGeneration;
use App\Domains\AI\Contracts\SupportsModelDiscovery;
use App\Domains\AI\Contracts\SupportsStreaming;
use App\Domains\AI\Contracts\SupportsTranscription;
use App\Domains\AI\Contracts\SupportsVision;
use App\Domains\AI\DTO\ChatMessage;
use App\Domains\AI\DTO\ChatRequest;
use App\Domains\AI\DTO\ChatResponse;
use App\Domains\AI\DTO\DiscoveredModel;
use App\Domains\AI\DTO\GeneratedImage;
use App\Domains\AI\DTO\ImageRequest;
use App\Domains\AI\DTO\TestResult;
use App\Domains\AI\DTO\Transcript;
use App\Domains\AI\DTO\TranscriptionRequest;
use App\Domains\AI\DTO\UsageMetrics;
use App\Domains\AI\Exceptions\ProviderFailed;
use App\Domains\AI\Support\Capability;
use App\Domains\AI\Support\ErrorClass;
use App\Domains\Diagnostics\Support\Category;
use App\Domains\Diagnostics\Support\CheckResult;
use App\Domains\Diagnostics\Support\Responsibility;
use App\Domains\Diagnostics\Support\Severity;
use App\Domains\Diagnostics\Support\Status;
use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * Google Gemini (blueprint §12).
 *
 * The adapter that justifies the whole architecture. Gemini disagrees with
 * OpenAI about nearly everything:
 *
 *   - messages are `contents`, each with `parts`, not `content`
 *   - the assistant's role is `model`, not `assistant`
 *   - the system prompt is `systemInstruction`, lifted out of the list
 *   - generation settings live under `generationConfig`
 *   - the key goes in a query string, not an Authorization header
 *   - the model identifier is part of the URL PATH, not the body
 *   - streaming is a JSON array over SSE, not OpenAI's delta frames
 *   - usage is `usageMetadata`, with different field names again
 *   - images come from `:predict`, with the count and the shape of the picture
 *     under `parameters` and the bytes under `predictions[].bytesBase64Encoded`
 *
 * Every one of those differences is contained here. Nothing above
 * ProviderAdapter changes, which is the point (§12).
 */
class GeminiAdapter extends BaseAdapter implements ContributesDiagnostics, SupportsChat, SupportsEmbeddings, SupportsImageGeneration, SupportsModelDiscovery, SupportsStreaming, SupportsTranscription, SupportsVision
{
    public const KEY = 'gemini';

    public const DEFAULT_BASE_URL = 'https://generativelanguage.googleapis.com/v1beta';

    public function capabilities(): array
    {
        return [
            Capability::CHAT,
            Capability::STREAMING,
            Capability::VISION,
            Capability::JSON_MODE,
            Capability::LONG_CONTEXT,
            Capability::EMBEDDINGS,
            Capability::IMAGE_GENERATION,
            Capability::TRANSCRIPTION,
        ];
    }

    /**
     * Turn text into vectors, Google's way.
     *
     * A DIFFERENT SHAPE FROM EVERYONE ELSE, which is the whole reason this
     * adapter exists: the batch endpoint is `:batchEmbedContents`, each input
     * is wrapped in its own request object, each of those has to repeat the
     * model name, and the vectors come back under `embeddings[].values`
     * rather than `data[].embedding`.
     *
     * Nothing above this method knows any of that. `EmbeddingService` asks for
     * vectors and gets vectors, exactly as chat asks for a reply.
     *
     * @param  array<int, string>  $inputs
     * @return array<int, array<int, float>>
     */
    public function embed(string $modelIdentifier, array $inputs): array
    {
        if ($inputs === []) {
            return [];
        }

        $requests = array_map(fn (string $text) => [
            // Repeated per request, and it must carry the `models/` prefix
            // even though the URL already names the model.
            'model' => 'models/'.$modelIdentifier,
            'content' => ['parts' => [['text' => $text]]],
        ], array_values($inputs));

        [$response] = $this->send(fn (PendingRequest $client) => $client->post(
            $this->modelUrl($modelIdentifier, 'batchEmbedContents'),
            ['requests' => $requests],
        ));

        $vectors = [];

        foreach ((array) data_get($response->json(), 'embeddings', []) as $row) {
            $values = data_get($row, 'values');

            if (is_array($values)) {
                $vectors[] = array_map('floatval', $values);
            }
        }

        if (count($vectors) !== count($inputs)) {
            // Gemini returns them in request order with no index to check
            // against, so a short response is the only signal that something
            // is misaligned — and a vector on the wrong chunk is a search
            // that quietly returns the wrong passage for ever.
            throw new ProviderFailed(ErrorClass::PROVIDER_ERROR);
        }

        return $vectors;
    }

    /**
     * Turn a recording into words, Google's way (§18).
     *
     * NOT A TRANSCRIPTION ENDPOINT AT ALL. Gemini has no `audio/transcriptions`
     * — audio is an ordinary PART of an ordinary chat message, sent inline as
     * base64 beside an instruction telling the model what to do with it. The
     * shape is the difference this adapter exists for, and containing it here
     * is what lets `VoiceService` ask for a transcript without knowing which
     * company answered.
     *
     * THE INSTRUCTION IS PART OF THE REQUEST, which is unlike every other
     * provider and worth being explicit about: without one the model
     * summarises or answers the audio rather than transcribing it, and the
     * customer's message becomes a reply to itself.
     *
     * NO DURATION COMES BACK. Google reports tokens, not seconds, so the
     * caller measures the audio itself rather than being handed a number that
     * would go straight into a charge.
     */
    public function transcribe(TranscriptionRequest $request): Transcript
    {
        $instruction = $request->language
            ? __('Transcribe this recording in :language. Reply with the transcript and nothing else.', ['language' => $request->language])
            : __('Transcribe this recording. Reply with the transcript and nothing else.');

        [$response] = $this->send(fn (PendingRequest $client) => $client->post(
            $this->modelUrl($request->modelIdentifier, 'generateContent'),
            [
                'contents' => [[
                    'role' => 'user',
                    'parts' => [
                        ['text' => $instruction],
                        ['inlineData' => [
                            'mimeType' => $request->mimeType,
                            'data' => base64_encode($request->bytes),
                        ]],
                    ],
                ]],
            ],
        ));

        $text = trim($this->extractText($response->json()));

        if ($text === '') {
            throw new ProviderFailed(ErrorClass::PROVIDER_ERROR);
        }

        return new Transcript(text: $text, language: $request->language);
    }

    /**
     * Make a picture, Google's way (§16).
     *
     * A FOURTH SHAPE, and every part of it disagrees with the one most of the
     * market uses. The method is `:predict` rather than an images endpoint;
     * the prompt goes inside an `instances` array; the count is
     * `sampleCount`, not `n`; the picture's shape is an ASPECT RATIO string
     * rather than a pixel size; and the bytes come back under
     * `predictions[].bytesBase64Encoded` with the MIME type beside them.
     *
     * A negative prompt is a first-class parameter here, where OpenAI-shaped
     * providers have no concept of one — so it is sent when the customer gave
     * one and omitted otherwise, rather than being folded into the prompt
     * text, which would change what the model was asked for.
     *
     * @return array<int, GeneratedImage>
     */
    public function generateImage(ImageRequest $request): array
    {
        $parameters = array_filter([
            'sampleCount' => max(1, $request->count),
            // Pixels are not a thing this API accepts. The ratio is what
            // survives translation from the size the customer chose.
            'aspectRatio' => $request->aspectRatio(),
            'negativePrompt' => $request->negativePrompt,
        ], static fn ($value) => $value !== null);

        [$response] = $this->send(fn (PendingRequest $client) => $client->post(
            $this->modelUrl($request->modelIdentifier, 'predict'),
            [
                'instances' => [['prompt' => $request->prompt]],
                'parameters' => $parameters,
            ],
        ));

        $images = [];

        foreach ((array) data_get($response->json(), 'predictions', []) as $row) {
            $encoded = data_get($row, 'bytesBase64Encoded');

            if (is_string($encoded) && $encoded !== '') {
                $mime = data_get($row, 'mimeType');

                $images[] = GeneratedImage::fromBase64($encoded, is_string($mime) ? $mime : null);
            }
        }

        if ($images === []) {
            // A 200 that produced nothing usable is a provider error: the
            // customer's credits are held against an image.
            throw new ProviderFailed(ErrorClass::PROVIDER_ERROR);
        }

        return $images;
    }

    public function chat(ChatRequest $request): ChatResponse
    {
        [$response, $latency] = $this->send(
            fn (PendingRequest $client) => $client->post(
                $this->modelUrl($request->modelIdentifier, 'generateContent'),
                $this->payload($request),
            ),
        );

        $body = $response->json();

        return new ChatResponse(
            content: $this->extractText($body),
            usage: new UsageMetrics(
                inputTokens: (int) data_get($body, 'usageMetadata.promptTokenCount', 0),
                outputTokens: (int) data_get($body, 'usageMetadata.candidatesTokenCount', 0),
            ),
            modelIdentifier: $request->modelIdentifier,
            finishReason: $this->normaliseFinishReason(data_get($body, 'candidates.0.finishReason')),
            latencyMs: $latency,
        );
    }

    public function streamChat(ChatRequest $request): Generator
    {
        $response = $this->client()
            ->withOptions(['stream' => true])
            ->withQueryParameters(['alt' => 'sse'])
            ->post(
                $this->modelUrl($request->modelIdentifier, 'streamGenerateContent'),
                $this->payload($request),
            );

        if ($response->failed()) {
            throw new ProviderFailed($this->classify($response), $response->status());
        }

        $body = $response->toPsrResponse()->getBody();
        $buffer = '';

        while (! $body->eof()) {
            $buffer .= $body->read(1024);

            while (($newline = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $newline));
                $buffer = substr($buffer, $newline + 1);

                if ($line === '' || ! str_starts_with($line, 'data:')) {
                    continue;
                }

                $chunk = json_decode(trim(substr($line, 5)), true);

                // Gemini sends a whole candidate per frame rather than a
                // delta, but each frame carries only the NEW text — so the
                // fragments still concatenate.
                $text = $this->extractText($chunk);

                if ($text !== '') {
                    yield $text;
                }
            }
        }
    }

    /** @return array<int, DiscoveredModel> */
    public function listModels(): array
    {
        [$response] = $this->send(fn (PendingRequest $client) => $client->get($this->url('models')));

        $models = [];

        foreach ((array) data_get($response->json(), 'models', []) as $entry) {
            $name = data_get($entry, 'name');

            if (! is_string($name) || $name === '') {
                continue;
            }

            // Gemini returns "models/gemini-x"; the identifier used everywhere
            // else is the part after the prefix.
            $identifier = str_starts_with($name, 'models/') ? substr($name, 7) : $name;

            // Unlike OpenAI, Gemini DOES say what each model supports — so
            // this is read rather than guessed.
            $methods = (array) data_get($entry, 'supportedGenerationMethods', []);

            if (! in_array('generateContent', $methods, true)) {
                continue;
            }

            $capabilities = [Capability::CHAT];

            if (in_array('streamGenerateContent', $methods, true)) {
                $capabilities[] = Capability::STREAMING;
            }

            $models[] = new DiscoveredModel(
                identifier: $identifier,
                displayName: (string) (data_get($entry, 'displayName') ?: $identifier),
                description: data_get($entry, 'description'),
                contextWindow: (int) data_get($entry, 'inputTokenLimit', 0) ?: null,
                maxOutputTokens: (int) data_get($entry, 'outputTokenLimit', 0) ?: null,
                capabilities: $capabilities,
            );
        }

        return $models;
    }

    public function testConnection(): TestResult
    {
        try {
            [$response, $latency] = $this->send(fn (PendingRequest $client) => $client->get($this->url('models')));

            $count = count((array) data_get($response->json(), 'models', []));

            return TestResult::pass($latency, $response->status(), ['models_visible' => $count]);
        } catch (ProviderFailed $e) {
            return TestResult::fail($e->errorClass, $e->latencyMs, $e->httpStatus);
        }
    }

    /** @return array<int, CheckResult> */
    public function diagnostics(): array
    {
        $result = $this->testConnection();
        $name = $this->provider->name;

        if ($result->success) {
            return [CheckResult::pass(
                'ai.provider.'.$this->provider->slug,
                $name.' connectivity',
                Category::AiProviders,
                Responsibility::Provider,
                sprintf('Reached %s in %dms; %d models visible.', $name, $result->latencyMs, $result->context['models_visible'] ?? 0),
            )];
        }

        return [new CheckResult(
            key: 'ai.provider.'.$this->provider->slug,
            title: $name.' connectivity',
            category: Category::AiProviders,
            status: Status::Red,
            severity: Severity::High,
            responsibility: $result->errorClass === ErrorClass::NETWORK ? Responsibility::Hosting : Responsibility::Provider,
            technicalReason: sprintf(
                '%s returned %s%s.',
                $name,
                ErrorClass::label((string) $result->errorClass),
                $result->httpStatus ? ' (HTTP '.$result->httpStatus.')' : '',
            ),
            recommendedAction: (string) $result->detail,
            adminAction: $result->errorClass === ErrorClass::AUTHENTICATION
                ? 'Open Admin → AI → Providers → '.$name.' and replace the API key from Google AI Studio.'
                : 'Open Admin → AI → Providers → '.$name.' and use Test connection for the current status.',
            requiresHostingSupport: $result->errorClass === ErrorClass::NETWORK,
            supportWording: $result->errorClass === ErrorClass::NETWORK
                ? 'Please allow outbound HTTPS connections from this hosting account to external APIs.'
                : '',
        )];
    }

    /**
     * Gemini reports a rejected key as HTTP 400 with a machine-readable
     * reason, where most APIs use 401.
     *
     * Without this an owner with an expired key would be told "the provider
     * rejected the shape of the request" and would go looking at their
     * settings instead of at their key.
     */
    protected function classify(Response $response): string
    {
        if ($response->status() === 400) {
            $reasons = (array) data_get($response->json(), 'error.details', []);

            foreach ($reasons as $detail) {
                // A machine-readable reason code, never the free-text message.
                if (($detail['reason'] ?? null) === 'API_KEY_INVALID') {
                    return ErrorClass::AUTHENTICATION;
                }
            }
        }

        if ($response->status() === 403
            && data_get($response->json(), 'error.status') === 'PERMISSION_DENIED') {
            return ErrorClass::AUTHORISATION;
        }

        return parent::classify($response);
    }

    // -- Gemini's own shape --------------------------------------------------

    /** The model identifier is part of the PATH, not the body. */
    private function modelUrl(string $modelIdentifier, string $method): string
    {
        return $this->url('models/'.$modelIdentifier.':'.$method);
    }

    /** @return array<string, mixed> */
    private function payload(ChatRequest $request): array
    {
        $payload = ['contents' => $this->contents($request)];

        // Lifted out of the message list entirely, unlike OpenAI.
        if ($system = $request->systemPrompt()) {
            $payload['systemInstruction'] = ['parts' => [['text' => $system]]];
        }

        $generationConfig = array_filter([
            'temperature' => $request->temperature,
            'maxOutputTokens' => $request->maxTokens,
        ], fn ($value) => $value !== null);

        if ($generationConfig !== []) {
            $payload['generationConfig'] = $generationConfig;
        }

        return $payload;
    }

    /** @return array<int, array<string, mixed>> */
    private function contents(ChatRequest $request): array
    {
        $contents = [];

        foreach ($request->conversation() as $message) {
            $parts = [];

            if (trim($message->content) !== '') {
                $parts[] = ['text' => $message->content];
            }

            foreach ($message->attachments as $attachment) {
                if (($attachment['type'] ?? '') !== 'image') {
                    continue;
                }

                // Gemini takes image bytes inline, base64, with their type —
                // not a URL as OpenAI does.
                if (! empty($attachment['data'])) {
                    $parts[] = ['inlineData' => [
                        'mimeType' => $attachment['mime'] ?? 'image/png',
                        'data' => $attachment['data'],
                    ]];
                }
            }

            if ($parts === []) {
                continue;
            }

            $contents[] = [
                // 'model', not 'assistant'.
                'role' => $message->role === ChatMessage::ROLE_ASSISTANT ? 'model' : 'user',
                'parts' => $parts,
            ];
        }

        return $contents;
    }

    /** Concatenate the text parts of a candidate. */
    private function extractText(mixed $body): string
    {
        $parts = data_get($body, 'candidates.0.content.parts', []);

        if (! is_array($parts)) {
            return '';
        }

        $text = '';

        foreach ($parts as $part) {
            if (is_string($part['text'] ?? null)) {
                $text .= $part['text'];
            }
        }

        return $text;
    }

    /**
     * Gemini's finish reasons into Aziv AI's vocabulary, so the chat system
     * does not have to learn a second set of words.
     */
    private function normaliseFinishReason(mixed $reason): ?string
    {
        return match ((string) $reason) {
            'STOP' => 'stop',
            'MAX_TOKENS' => 'length',
            'SAFETY', 'RECITATION', 'BLOCKLIST', 'PROHIBITED_CONTENT' => 'content_filter',
            '' => null,
            default => strtolower((string) $reason),
        };
    }
}
