<?php

namespace App\Domains\AI\Adapters;

use App\Domains\AI\Contracts\SupportsChat;
use App\Domains\AI\DTO\ChatMessage;
use App\Domains\AI\DTO\ChatRequest;
use App\Domains\AI\DTO\ChatResponse;
use App\Domains\AI\DTO\TestResult;
use App\Domains\AI\DTO\UsageMetrics;
use App\Domains\AI\Exceptions\ProviderFailed;
use App\Domains\AI\Models\CustomProviderMapping;
use App\Domains\AI\Support\Capability;
use App\Domains\AI\Support\ErrorClass;

/**
 * A provider described entirely from the Admin Panel (§10, §12).
 *
 * The administrator supplies three things per capability: where the endpoint
 * is, what shape to send, and where in the answer the useful parts live. This
 * adapter builds the call from that description.
 *
 * THE HONEST LIMIT. Blueprint §12 says Aziv AI will not claim every API on the
 * internet is automatically supported, and this class is where that line sits.
 * It handles JSON request/response APIs over HTTP. Binary protocols, non-HTTP
 * transports and bespoke authentication handshakes still need a purpose-built
 * adapter — an isolated job touching one new class, but a development job.
 */
class CustomHttpAdapter extends BaseAdapter implements SupportsChat
{
    public const KEY = 'custom_http';

    /**
     * Placeholders an administrator can use in a request template.
     *
     * A closed set, substituted literally. The template is NOT evaluated as
     * code or as a Blade string — a template is configuration written by a
     * person, and configuration must never become a way to execute something.
     */
    public const PLACEHOLDERS = [
        '{{model}}' => 'The model identifier',
        '{{prompt}}' => 'The latest user message as plain text',
        '{{messages}}' => 'The whole conversation as a list of role/content pairs',
        '{{system}}' => 'The system prompt, if there is one',
        '{{max_tokens}}' => 'The output limit for this request',
        '{{temperature}}' => 'The creativity setting',
    ];

    /**
     * Stands in for the stored API key inside a header template, so the key
     * itself is never typed into an unencrypted column.
     */
    public const CREDENTIAL_PLACEHOLDER = '{{credential}}';

    public function capabilities(): array
    {
        if (! isset($this->provider)) {
            return [Capability::CHAT];
        }

        return $this->provider->mappings()->pluck('capability')->all() ?: [Capability::CHAT];
    }

    public function chat(ChatRequest $request): ChatResponse
    {
        $mapping = $this->mapping(Capability::CHAT);

        $payload = $this->substitute((array) $mapping->request_template, $request);
        $method = strtolower($mapping->http_method ?: 'POST');

        [$response, $latency] = $this->send(function ($client) use ($mapping, $method, $payload) {
            $client = $client->withHeaders($this->resolveHeaders($mapping));

            return $method === 'get'
                ? $client->get($this->url($mapping->endpoint_path), $payload)
                : $client->{$method}($this->url($mapping->endpoint_path), $payload);
        });

        $body = $response->json();
        $map = (array) $mapping->response_mapping;

        return new ChatResponse(
            // Where the text lives is the administrator's description, with a
            // sensible default so a minimal mapping still works.
            content: (string) data_get($body, $map['content'] ?? 'choices.0.message.content', ''),
            usage: new UsageMetrics(
                inputTokens: (int) data_get($body, $map['input_tokens'] ?? 'usage.prompt_tokens', 0),
                outputTokens: (int) data_get($body, $map['output_tokens'] ?? 'usage.completion_tokens', 0),
            ),
            modelIdentifier: (string) data_get($body, $map['model'] ?? 'model', $request->modelIdentifier),
            finishReason: data_get($body, $map['finish_reason'] ?? 'choices.0.finish_reason'),
            latencyMs: $latency,
        );
    }

    public function testConnection(): TestResult
    {
        $mapping = $this->provider->mappings()->where('capability', Capability::CHAT)->first();

        if (! $mapping) {
            return TestResult::fail(ErrorClass::INVALID_REQUEST, 0, null, [
                'reason' => 'No request mapping has been described for this provider yet.',
            ]);
        }

        try {
            // A real, deliberately tiny exchange — a test must never be able
            // to become expensive (§25).
            $request = new ChatRequest(
                modelIdentifier: (string) ($this->provider->settings['test_model'] ?? 'test'),
                messages: [ChatMessage::user('ping')],
                maxTokens: 5,
            );

            $started = hrtime(true);
            $this->chat($request);

            return TestResult::pass($this->elapsed($started));
        } catch (ProviderFailed $e) {
            return TestResult::fail($e->errorClass, $e->latencyMs, $e->httpStatus);
        }
    }

    private function mapping(string $capability): CustomProviderMapping
    {
        $mapping = $this->provider->mappings()->where('capability', $capability)->first();

        if (! $mapping) {
            throw new ProviderFailed(ErrorClass::INVALID_REQUEST);
        }

        return $mapping;
    }

    /**
     * Replace placeholders throughout a template, at any depth.
     *
     * Values are substituted as DATA. A placeholder standing alone becomes the
     * real value (so {{messages}} can become an array); one inside a longer
     * string is interpolated as text.
     */
    private function substitute(array $template, ChatRequest $request): array
    {
        $values = [
            '{{model}}' => $request->modelIdentifier,
            '{{prompt}}' => $this->latestPrompt($request),
            '{{messages}}' => $request->messagesArray(),
            '{{system}}' => $request->systemPrompt() ?? '',
            '{{max_tokens}}' => $request->maxTokens ?? 512,
            '{{temperature}}' => $request->temperature ?? 0.7,
        ];

        return $this->walk($template, $values);
    }

    private function walk(array $node, array $values): array
    {
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $node[$key] = $this->walk($value, $values);

                continue;
            }

            if (! is_string($value)) {
                continue;
            }

            if (array_key_exists($value, $values)) {
                // The whole value is one placeholder, so it keeps its real
                // type — an array stays an array rather than becoming "Array".
                $node[$key] = $values[$value];

                continue;
            }

            foreach ($values as $placeholder => $replacement) {
                if (is_scalar($replacement) && str_contains($value, $placeholder)) {
                    $value = str_replace($placeholder, (string) $replacement, $value);
                }
            }

            $node[$key] = $value;
        }

        return $node;
    }

    /**
     * Extra headers for this call.
     *
     * SECURITY. `headers_template` is an ordinary JSON column — it is NOT
     * encrypted, because it describes a shape rather than holding a secret. An
     * administrator who pasted a real key in here would be writing it to the
     * database in plaintext and to every backup of it.
     *
     * So the key is never typed here. Authentication is applied by BaseAdapter
     * from the ENCRYPTED credential, and a template that needs the key
     * somewhere unusual writes `{{credential}}`, which is substituted at call
     * time. The admin form refuses anything that looks like a literal secret.
     *
     * @return array<string, string>
     */
    private function resolveHeaders(CustomProviderMapping $mapping): array
    {
        $headers = [];

        foreach ((array) $mapping->headers_template as $name => $value) {
            if (! is_string($name) || ! is_scalar($value)) {
                continue;
            }

            $headers[$name] = str_contains((string) $value, self::CREDENTIAL_PLACEHOLDER)
                ? str_replace(self::CREDENTIAL_PLACEHOLDER, $this->credential()->secret(), (string) $value)
                : (string) $value;
        }

        return $headers;
    }

    private function latestPrompt(ChatRequest $request): string
    {
        $conversation = $request->conversation();

        return $conversation === [] ? '' : end($conversation)->content;
    }
}
