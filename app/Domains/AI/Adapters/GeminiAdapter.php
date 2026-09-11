<?php

namespace App\Domains\AI\Adapters;

use App\Domains\AI\Contracts\ContributesDiagnostics;
use App\Domains\AI\Contracts\SupportsChat;
use App\Domains\AI\Contracts\SupportsModelDiscovery;
use App\Domains\AI\Contracts\SupportsStreaming;
use App\Domains\AI\Contracts\SupportsVision;
use App\Domains\AI\DTO\ChatMessage;
use App\Domains\AI\DTO\ChatRequest;
use App\Domains\AI\DTO\ChatResponse;
use App\Domains\AI\DTO\DiscoveredModel;
use App\Domains\AI\DTO\TestResult;
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
 *
 * Every one of those differences is contained here. Nothing above
 * ProviderAdapter changes, which is the point (§12).
 */
class GeminiAdapter extends BaseAdapter implements ContributesDiagnostics, SupportsChat, SupportsModelDiscovery, SupportsStreaming, SupportsVision
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
        ];
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
