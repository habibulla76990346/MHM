<?php

namespace App\Domains\AI\Adapters;

use App\Domains\AI\Contracts\ContributesDiagnostics;
use App\Domains\AI\Contracts\SupportsChat;
use App\Domains\AI\Contracts\SupportsEmbeddings;
use App\Domains\AI\Contracts\SupportsImageGeneration;
use App\Domains\AI\Contracts\SupportsModelDiscovery;
use App\Domains\AI\Contracts\SupportsSpeech;
use App\Domains\AI\Contracts\SupportsStreaming;
use App\Domains\AI\Contracts\SupportsTranscription;
use App\Domains\AI\Contracts\SupportsVision;
use App\Domains\AI\DTO\ChatRequest;
use App\Domains\AI\DTO\ChatResponse;
use App\Domains\AI\DTO\DiscoveredModel;
use App\Domains\AI\DTO\GeneratedImage;
use App\Domains\AI\DTO\ImageRequest;
use App\Domains\AI\DTO\SpeechRequest;
use App\Domains\AI\DTO\SynthesisedSpeech;
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

/**
 * The commercially important adapter.
 *
 * A large majority of AI providers deliberately copy OpenAI's API shape —
 * DeepSeek, Groq, Mistral, xAI, Together, OpenRouter, Fireworks, Cerebras,
 * Perplexity and many more. One well-built adapter serves them all, which
 * turns "add a provider" from a development task into pasting a base URL and a
 * key into the Admin Panel.
 *
 * Nothing here names a provider or a model. It speaks the shape, and the
 * catalog supplies the identifiers (Rule 5).
 */
class OpenAiCompatibleAdapter extends BaseAdapter implements ContributesDiagnostics, SupportsChat, SupportsEmbeddings, SupportsImageGeneration, SupportsModelDiscovery, SupportsSpeech, SupportsStreaming, SupportsTranscription, SupportsVision
{
    public const KEY = 'openai_compatible';

    /** A test must never be able to become expensive (§25). */
    private const TEST_MAX_TOKENS = 5;

    public function capabilities(): array
    {
        return [
            Capability::CHAT,
            Capability::STREAMING,
            Capability::VISION,
            Capability::TOOL_USE,
            Capability::JSON_MODE,
            Capability::EMBEDDINGS,
            Capability::IMAGE_GENERATION,
            Capability::TRANSCRIPTION,
            Capability::SPEECH,
        ];
    }

    /**
     * Turn text into vectors (§17).
     *
     * ONE REQUEST FOR THE WHOLE BATCH, because the alternative — a call per
     * chunk — turns a 300-chunk document into 300 round trips and 300 chances
     * for a rate limit. The shape is the same one OpenAI defined, so every
     * compatible provider answers it.
     *
     * The order that comes back is NOT trusted. The response carries an
     * `index` per row and it is used to reorder, because a vector attached to
     * the wrong chunk is not an error anybody sees — it is a search that
     * quietly returns the wrong passage for ever.
     *
     * @param  array<int, string>  $inputs
     * @return array<int, array<int, float>>
     */
    public function embed(string $modelIdentifier, array $inputs): array
    {
        if ($inputs === []) {
            return [];
        }

        [$response] = $this->send(fn ($client) => $client->post($this->url('embeddings'), [
            'model' => $modelIdentifier,
            'input' => array_values($inputs),
        ]));

        $vectors = [];

        foreach ((array) data_get($response->json(), 'data', []) as $row) {
            $vector = data_get($row, 'embedding');

            if (! is_array($vector)) {
                continue;
            }

            $index = (int) (data_get($row, 'index') ?? count($vectors));
            $vectors[$index] = array_map('floatval', $vector);
        }

        ksort($vectors);

        if (count($vectors) !== count($inputs)) {
            // Silently returning fewer vectors than chunks would attach every
            // subsequent vector to the wrong chunk.
            throw new ProviderFailed(ErrorClass::PROVIDER_ERROR);
        }

        return array_values($vectors);
    }

    /**
     * Make a picture (§16).
     *
     * THE SHAPE OPENAI DEFINED, which is the one most of the market copied —
     * so this single method serves every OpenAI-compatible provider that
     * offers images, and adding one of them stays a row rather than a class.
     *
     * `b64_json` RATHER THAN A URL, deliberately. A URL means a second HTTP
     * call to a CDN this application never authorised, and those URLs expire
     * within the hour — long enough to pass a test and short enough to fail
     * the first time a queue runs behind. Asking for the bytes makes the
     * response self-contained. A provider that ignores the parameter and
     * returns a URL anyway is still handled: the caller downloads it.
     *
     * The parameters are sent only when they were ASKED for. A provider that
     * has never heard of `quality` returns a 400 for it, and defaulting every
     * request to carry one would break exactly the providers this adapter
     * exists to support without code.
     *
     * @return array<int, GeneratedImage>
     */
    public function generateImage(ImageRequest $request): array
    {
        $payload = array_filter([
            'model' => $request->modelIdentifier,
            'prompt' => $request->prompt,
            'n' => max(1, $request->count),
            'size' => $request->size,
            'response_format' => 'b64_json',
            'quality' => $request->quality === 'standard' ? null : $request->quality,
            'style' => $request->style,
        ], static fn ($value) => $value !== null);

        [$response] = $this->send(fn ($client) => $client->post($this->url('images/generations'), $payload));

        $images = [];

        foreach ((array) data_get($response->json(), 'data', []) as $row) {
            $encoded = data_get($row, 'b64_json');
            $revised = data_get($row, 'revised_prompt');

            if (is_string($encoded) && $encoded !== '') {
                $images[] = GeneratedImage::fromBase64($encoded, revisedPrompt: is_string($revised) ? $revised : null);

                continue;
            }

            $url = data_get($row, 'url');

            if (is_string($url) && $url !== '') {
                $images[] = new GeneratedImage(url: $url, revisedPrompt: is_string($revised) ? $revised : null);
            }
        }

        if ($images === []) {
            // A 200 carrying nothing usable is a provider error, not an empty
            // result: the customer's credits are held against an image.
            throw new ProviderFailed(ErrorClass::PROVIDER_ERROR);
        }

        return $images;
    }

    /**
     * Turn a recording into words (§18).
     *
     * MULTIPART, WHICH IS THE ONE THING THAT MAKES THIS DIFFERENT from every
     * other call in this adapter: the audio is attached as a file part, not
     * encoded into JSON. Sending a minute of audio as base64 inside a JSON
     * body inflates it by a third and several providers simply reject it.
     *
     * The bytes come from the caller rather than a path. An adapter that read
     * a disk would be the one place the "environment is configuration" rule
     * broke — the file is local on one deployment and on S3 on the next.
     */
    public function transcribe(TranscriptionRequest $request): Transcript
    {
        [$response] = $this->send(function ($client) use ($request) {
            $client = $client->asMultipart()->attach(
                'file',
                $request->bytes,
                $request->filename,
                ['Content-Type' => $request->mimeType],
            );

            return $client->post($this->url('audio/transcriptions'), array_filter([
                'model' => $request->modelIdentifier,
                'language' => $request->language,
                // The verbose form carries the DURATION, which is what the
                // customer is charged against. Without it the caller has to
                // measure the audio itself or guess, and a guess in a billing
                // path is not acceptable. A provider that ignores the
                // parameter returns the plain form and the caller measures.
                'response_format' => 'verbose_json',
            ], static fn ($value) => $value !== null));
        });

        $body = $response->json();
        $text = data_get($body, 'text');

        if (! is_string($text)) {
            throw new ProviderFailed(ErrorClass::PROVIDER_ERROR);
        }

        $language = data_get($body, 'language');
        $duration = data_get($body, 'duration');

        return new Transcript(
            text: trim($text),
            language: is_string($language) ? mb_substr($language, 0, 12) : null,
            seconds: is_numeric($duration) ? (float) $duration : 0.0,
        );
    }

    /**
     * Read words aloud (§18).
     *
     * THE RESPONSE IS NOT JSON. This is the only call in the adapter layer
     * whose body is binary, which matters twice: `->json()` on it returns
     * null, and an error body IS JSON — so a failure has to be classified
     * before the bytes are touched, which `send()` already does.
     */
    public function synthesise(SpeechRequest $request): SynthesisedSpeech
    {
        [$response] = $this->send(fn ($client) => $client->post($this->url('audio/speech'), array_filter([
            'model' => $request->modelIdentifier,
            'input' => $request->text,
            'voice' => $request->voice,
            'response_format' => $request->format,
        ], static fn ($value) => $value !== null)));

        $bytes = $response->body();

        if ($bytes === '') {
            throw new ProviderFailed(ErrorClass::PROVIDER_ERROR);
        }

        return new SynthesisedSpeech(
            bytes: $bytes,
            // The header is a claim; the caller re-reads the type from the
            // bytes before storing. Passed on because it is useful context,
            // never because it is trusted.
            mimeType: $response->header('Content-Type') ?: null,
        );
    }

    public function chat(ChatRequest $request): ChatResponse
    {
        [$response, $latency] = $this->send(
            fn ($client) => $client->post($this->url('chat/completions'), $this->payload($request)),
        );

        $body = $response->json();

        return new ChatResponse(
            content: (string) data_get($body, 'choices.0.message.content', ''),
            usage: new UsageMetrics(
                inputTokens: (int) data_get($body, 'usage.prompt_tokens', 0),
                outputTokens: (int) data_get($body, 'usage.completion_tokens', 0),
            ),
            modelIdentifier: (string) data_get($body, 'model', $request->modelIdentifier),
            finishReason: data_get($body, 'choices.0.finish_reason'),
            latencyMs: $latency,
        );
    }

    public function streamChat(ChatRequest $request): Generator
    {
        $payload = $this->payload($request) + ['stream' => true];

        $response = $this->client()
            ->withOptions(['stream' => true])
            ->post($this->url('chat/completions'), $payload);

        if ($response->failed()) {
            throw new ProviderFailed($this->classify($response), $response->status());
        }

        $body = $response->toPsrResponse()->getBody();
        $buffer = '';

        while (! $body->eof()) {
            $buffer .= $body->read(1024);

            // Server-sent events are newline delimited; a read can land
            // mid-event, so only complete lines are consumed.
            while (($newline = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $newline));
                $buffer = substr($buffer, $newline + 1);

                if ($line === '' || ! str_starts_with($line, 'data:')) {
                    continue;
                }

                $data = trim(substr($line, 5));

                if ($data === '[DONE]') {
                    return;
                }

                $chunk = json_decode($data, true);
                $text = data_get($chunk, 'choices.0.delta.content');

                if (is_string($text) && $text !== '') {
                    yield $text;
                }
            }
        }
    }

    /**
     * Rule 5: the list comes from the provider, never from a constant here.
     *
     * @return array<int, DiscoveredModel>
     */
    public function listModels(): array
    {
        [$response] = $this->send(fn ($client) => $client->get($this->url('models')));

        $models = [];

        foreach ((array) data_get($response->json(), 'data', []) as $entry) {
            $id = data_get($entry, 'id');

            if (! is_string($id) || $id === '') {
                continue;
            }

            $models[] = new DiscoveredModel(
                identifier: $id,
                displayName: is_string(data_get($entry, 'name')) ? data_get($entry, 'name') : $id,
                description: is_string(data_get($entry, 'description')) ? data_get($entry, 'description') : null,
                contextWindow: (int) (data_get($entry, 'context_length') ?? data_get($entry, 'context_window') ?? 0) ?: null,
                maxOutputTokens: (int) (data_get($entry, 'max_output_tokens') ?? 0) ?: null,
                // Capabilities are NOT guessed from the identifier. A name is
                // not a contract, and an administrator ticking a box they can
                // verify beats a regex that is wrong for the next model.
                capabilities: [Capability::CHAT, Capability::STREAMING],
            );
        }

        return $models;
    }

    public function testConnection(): TestResult
    {
        try {
            // The model list is the cheapest real exchange that proves the
            // key works: it is authenticated, and it costs nothing.
            [$response, $latency] = $this->send(fn ($client) => $client->get($this->url('models')));

            $count = count((array) data_get($response->json(), 'data', []));

            return TestResult::pass($latency, $response->status(), ['models_visible' => $count]);
        } catch (ProviderFailed $e) {
            return TestResult::fail($e->errorClass, $e->latencyMs, $e->httpStatus);
        }
    }

    /**
     * Owner Addendum G: the adapter reports on itself, because how a provider
     * signals trouble is exactly the provider-specific knowledge this layer
     * exists to contain.
     *
     * @return array<int, CheckResult>
     */
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
                ? 'Open Admin → AI Providers → '.$name.' and replace the API key.'
                : 'Open Admin → AI Providers → '.$name.' and use Test connection for the current status.',
            requiresHostingSupport: $result->errorClass === ErrorClass::NETWORK,
            supportWording: $result->errorClass === ErrorClass::NETWORK
                ? 'Please allow outbound HTTPS connections from this hosting account to external APIs.'
                : '',
        )];
    }

    /** @return array<string, mixed> */
    private function payload(ChatRequest $request): array
    {
        $messages = [];

        foreach ($request->messages as $message) {
            if ($message->attachments === []) {
                $messages[] = ['role' => $message->role, 'content' => $message->content];

                continue;
            }

            // The multimodal content-parts shape, used by every provider that
            // follows OpenAI's schema.
            $parts = [['type' => 'text', 'text' => $message->content]];

            foreach ($message->attachments as $attachment) {
                if (($attachment['type'] ?? '') === 'image' && ! empty($attachment['url'])) {
                    $parts[] = ['type' => 'image_url', 'image_url' => ['url' => $attachment['url']]];
                }
            }

            $messages[] = ['role' => $message->role, 'content' => $parts];
        }

        return array_filter([
            'model' => $request->modelIdentifier,
            'messages' => $messages,
            'temperature' => $request->temperature,
            'max_tokens' => $request->maxTokens,
        ], fn ($value) => $value !== null);
    }

    public function testChatPayloadMaxTokens(): int
    {
        return self::TEST_MAX_TOKENS;
    }
}
