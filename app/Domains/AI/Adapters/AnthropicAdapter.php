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
 * Anthropic's Messages API (blueprint §12, Phase 7).
 *
 * FOUR THINGS ARE GENUINELY DIFFERENT here, and each is why this needs its own
 * adapter rather than the OpenAI-compatible one:
 *
 *  1. THE SYSTEM PROMPT IS NOT A MESSAGE. It is a top-level `system` field.
 *     Sending it as `{role: "system"}` inside `messages` is rejected — so the
 *     one thing every persona in this product depends on would fail on every
 *     request.
 *  2. `max_tokens` IS REQUIRED. OpenAI treats it as optional; here a request
 *     without it is a 400, so the adapter always sends one.
 *  3. AUTHENTICATION IS `x-api-key` PLUS A VERSION HEADER, not a bearer token.
 *     Both are set here rather than left to the provider row, because getting
 *     either wrong produces an authentication error that looks like a bad key.
 *  4. STREAMING IS TYPED EVENTS, not `choices[].delta`. Text arrives as
 *     `content_block_delta` with `delta.type = "text_delta"`, and the stream
 *     ends on `message_stop` rather than a `[DONE]` sentinel.
 *
 * NO MODEL NAME APPEARS IN THIS FILE (Rule 5). The catalog supplies
 * identifiers; `listModels()` reads them from the provider.
 *
 * NO SDK. Every adapter in this product speaks HTTP through the same base
 * class, which is what keeps "adding a provider" a single-file change and
 * keeps deployment to a shared host a copy rather than a dependency install.
 */
class AnthropicAdapter extends BaseAdapter implements ContributesDiagnostics, SupportsChat, SupportsModelDiscovery, SupportsStreaming, SupportsVision
{
    public const KEY = 'anthropic';

    public const DEFAULT_BASE_URL = 'https://api.anthropic.com/v1';

    /**
     * The API version this adapter is written against.
     *
     * Anthropic versions its API by header rather than by URL, and an omitted
     * version is an error. Pinning it here means a future breaking change
     * cannot silently alter the shape this adapter parses — the response stays
     * the one it was written for until somebody deliberately moves it.
     */
    public const API_VERSION = '2023-06-01';

    /**
     * What a request must carry when the caller did not say.
     *
     * Required by the API, so there is no "unset" to pass through. Generous
     * enough not to truncate an ordinary reply, and the router overrides it
     * from the model's own ceiling on every real call.
     */
    private const DEFAULT_MAX_TOKENS = 4096;

    /** A test must never be able to become expensive (§25). */
    private const TEST_MAX_TOKENS = 4;

    public function capabilities(): array
    {
        return [
            Capability::CHAT,
            Capability::STREAMING,
            Capability::VISION,
            Capability::TOOL_USE,
        ];
    }

    /**
     * `x-api-key` and the version header, set here and not by configuration.
     *
     * The base class offers bearer, header and query auth because providers
     * differ; this provider's shape is not a choice an owner should have to
     * get right, and a missing version header fails in a way that looks
     * exactly like a rejected key.
     */
    protected function client(): PendingRequest
    {
        $credential = $this->credential();

        return $this->baseRequest()
            ->withHeaders([
                'x-api-key' => $credential->secret(),
                'anthropic-version' => self::API_VERSION,
            ]);
    }

    // -- chat -----------------------------------------------------------------

    public function chat(ChatRequest $request): ChatResponse
    {
        [$response, $latency] = $this->send(
            fn ($client) => $client->post($this->url('messages'), $this->payload($request)),
        );

        $body = (array) $response->json();

        return new ChatResponse(
            content: $this->textFrom($body),
            usage: new UsageMetrics(
                inputTokens: (int) data_get($body, 'usage.input_tokens', 0),
                outputTokens: (int) data_get($body, 'usage.output_tokens', 0),
            ),
            modelIdentifier: (string) data_get($body, 'model', $request->modelIdentifier),
            finishReason: data_get($body, 'stop_reason'),
            latencyMs: $latency,
        );
    }

    /**
     * Stream a reply.
     *
     * The event stream is typed rather than a single delta shape, so only
     * `text_delta` is yielded: thinking blocks and tool-use blocks arrive on
     * the same channel, and forwarding those verbatim would put the model's
     * internal reasoning into a customer's chat bubble.
     */
    public function streamChat(ChatRequest $request): Generator
    {
        $payload = $this->payload($request) + ['stream' => true];

        $response = $this->client()
            ->withOptions(['stream' => true])
            ->post($this->url('messages'), $payload);

        if ($response->failed()) {
            throw new ProviderFailed($this->classify($response), $response->status());
        }

        $body = $response->toPsrResponse()->getBody();
        $buffer = '';

        while (! $body->eof()) {
            $buffer .= $body->read(1024);

            // Server-sent events are newline delimited, and a read can land
            // mid-event, so only complete lines are consumed.
            while (($newline = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $newline));
                $buffer = substr($buffer, $newline + 1);

                if ($line === '' || ! str_starts_with($line, 'data:')) {
                    continue;
                }

                $event = json_decode(trim(substr($line, 5)), true);

                if (! is_array($event)) {
                    continue;
                }

                // The stream ends on an event, not on a sentinel string.
                if (($event['type'] ?? '') === 'message_stop') {
                    return;
                }

                // An error can arrive MID-STREAM, after a 200 and after some
                // text. Ignoring it would end the reply silently and leave the
                // customer with a truncated answer and no explanation.
                if (($event['type'] ?? '') === 'error') {
                    throw new ProviderFailed(
                        $this->classifyErrorType((string) data_get($event, 'error.type', '')),
                    );
                }

                if (($event['type'] ?? '') !== 'content_block_delta') {
                    continue;
                }

                // Only visible text. `thinking_delta` and `input_json_delta`
                // travel the same channel and are not the reply.
                if (data_get($event, 'delta.type') !== 'text_delta') {
                    continue;
                }

                $text = data_get($event, 'delta.text');

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
        [$response] = $this->send(fn ($client) => $client->get($this->url('models'), ['limit' => 100]));

        $models = [];

        foreach ((array) data_get($response->json(), 'data', []) as $entry) {
            $id = data_get($entry, 'id');

            if (! is_string($id) || $id === '') {
                continue;
            }

            $models[] = new DiscoveredModel(
                identifier: $id,
                displayName: is_string(data_get($entry, 'display_name')) ? data_get($entry, 'display_name') : $id,
                // The listing carries these on newer API versions and omits
                // them on older ones. Absent means "not stated", which the
                // catalog shows as unknown rather than inventing a number.
                contextWindow: (int) (data_get($entry, 'max_input_tokens') ?? 0) ?: null,
                maxOutputTokens: (int) (data_get($entry, 'max_tokens') ?? 0) ?: null,
                // NOT inferred from the identifier. A name is not a contract,
                // and an administrator ticking a box they can verify beats a
                // regex that is wrong for the next model released.
                capabilities: [Capability::CHAT, Capability::STREAMING],
            );
        }

        return $models;
    }

    public function testConnection(): TestResult
    {
        try {
            // The model list is the cheapest real exchange that proves the key
            // works: authenticated, and it costs nothing to run.
            [$response, $latency] = $this->send(
                fn ($client) => $client->get($this->url('models'), ['limit' => 1]),
            );

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

        return [new CheckResult(
            key: 'ai.provider.'.$this->provider->getKey(),
            title: $this->provider->name.' connectivity',
            category: Category::AiProviders,
            status: $result->success ? Status::Green : Status::Red,
            severity: $result->success ? Severity::Informational : Severity::High,
            responsibility: Responsibility::Provider,
            technicalReason: $result->success
                ? 'Authenticated in '.$result->latencyMs.' ms.'
                : $result->label(),
            recommendedAction: $result->success
                ? 'Reachable and authenticated.'
                : ErrorClass::action((string) $result->errorClass),
            adminAction: $result->success
                ? 'Nothing to do.'
                : 'Open Admin → AI Providers → '.$this->provider->name.' and use Test connection.',
        )];
    }

    // -- translation ----------------------------------------------------------

    /**
     * Aziv AI's request, in this provider's shape.
     *
     * @return array<string, mixed>
     */
    private function payload(ChatRequest $request): array
    {
        $payload = [
            'model' => $request->modelIdentifier,
            // Required by the API. There is no "leave it to the provider".
            'max_tokens' => $request->maxTokens ?: self::DEFAULT_MAX_TOKENS,
            'messages' => $this->conversation($request),
        ];

        // LIFTED OUT of the message list, which is the whole reason this
        // adapter exists. A persona sent as a message would be rejected.
        $system = $request->systemPrompt();

        if (filled($system)) {
            $payload['system'] = $system;
        }

        if ($request->temperature !== null) {
            $payload['temperature'] = $request->temperature;
        }

        return $payload;
    }

    /**
     * The conversation, with images as content blocks.
     *
     * @return array<int, array<string, mixed>>
     */
    private function conversation(ChatRequest $request): array
    {
        $messages = [];

        foreach ($request->conversation() as $message) {
            $messages[] = [
                'role' => $message->role === ChatMessage::ROLE_ASSISTANT ? 'assistant' : 'user',
                'content' => $this->content($message),
            ];
        }

        // A conversation must not be empty, and must not open on the
        // assistant. Both are 400s, and both are reachable — the first turn
        // of a regeneration has no user message before it.
        if ($messages === []) {
            $messages[] = ['role' => 'user', 'content' => ''];
        }

        return $messages;
    }

    /**
     * One message's content.
     *
     * A plain string where there are no attachments — the API accepts both,
     * and the simpler shape is easier to read in a log and cheaper to encode.
     *
     * @return string|array<int, array<string, mixed>>
     */
    private function content(ChatMessage $message): string|array
    {
        if ($message->attachments === []) {
            return $message->content;
        }

        $blocks = [];

        foreach ($message->attachments as $attachment) {
            if (($attachment['type'] ?? '') !== 'image') {
                continue;
            }

            $data = $attachment['data'] ?? null;

            // Base64 bytes only. Files live on the private disk with no URL
            // (Addendum H), so there is nothing for a provider to fetch.
            if (! is_string($data) || $data === '') {
                continue;
            }

            $blocks[] = [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => $attachment['mime'] ?? 'image/png',
                    'data' => $data,
                ],
            ];
        }

        // Images first, then the question about them: a prompt that arrives
        // before the picture it refers to reads as if the picture is missing.
        if ($message->content !== '') {
            $blocks[] = ['type' => 'text', 'text' => $message->content];
        }

        return $blocks === [] ? $message->content : $blocks;
    }

    /**
     * The reply text out of a block list.
     *
     * Concatenates every text block and ignores the rest: a response can also
     * carry thinking and tool-use blocks, and neither belongs in a chat
     * bubble.
     *
     * @param  array<string, mixed>  $body
     */
    private function textFrom(array $body): string
    {
        $text = '';

        foreach ((array) data_get($body, 'content', []) as $block) {
            if (($block['type'] ?? '') === 'text' && is_string($block['text'] ?? null)) {
                $text .= $block['text'];
            }
        }

        return $text;
    }

    /**
     * This provider's error vocabulary, mapped to Aziv AI's.
     *
     * Read from the machine-readable `error.type` and never from the message
     * text — several APIs echo the failing request back in that text, and the
     * request carried the key.
     */
    protected function classify(Response $response): string
    {
        $type = (string) data_get($response->json(), 'error.type', '');

        return $type !== ''
            ? $this->classifyErrorType($type)
            : ErrorClass::fromHttpStatus($response->status());
    }

    private function classifyErrorType(string $type): string
    {
        return match ($type) {
            'authentication_error' => ErrorClass::AUTHENTICATION,
            'permission_error' => ErrorClass::AUTHORISATION,
            'rate_limit_error' => ErrorClass::RATE_LIMIT,
            'not_found_error' => ErrorClass::MODEL_NOT_FOUND,
            'request_too_large' => ErrorClass::CONTEXT_TOO_LONG,
            'invalid_request_error' => ErrorClass::INVALID_REQUEST,
            // Transient by definition: the provider is telling us to come
            // back, which is exactly what the retry policy is for.
            'overloaded_error', 'api_error' => ErrorClass::PROVIDER_ERROR,
            default => ErrorClass::UNKNOWN,
        };
    }
}
