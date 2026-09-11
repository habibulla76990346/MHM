<?php

namespace App\Domains\AI\Adapters;

use App\Domains\AI\Contracts\ContributesDiagnostics;
use App\Domains\AI\Contracts\SupportsChat;
use App\Domains\AI\Contracts\SupportsModelDiscovery;
use App\Domains\AI\Contracts\SupportsStreaming;
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
class OpenAiCompatibleAdapter extends BaseAdapter implements ContributesDiagnostics, SupportsChat, SupportsModelDiscovery, SupportsStreaming
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
        ];
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
