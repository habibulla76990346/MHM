<?php

namespace Tests\Feature\AI;

use App\Domains\AI\Adapters\AnthropicAdapter;
use App\Domains\AI\Contracts\SupportsChat;
use App\Domains\AI\Contracts\SupportsModelDiscovery;
use App\Domains\AI\Contracts\SupportsStreaming;
use App\Domains\AI\DTO\ChatMessage;
use App\Domains\AI\DTO\ChatRequest;
use App\Domains\AI\Exceptions\ProviderFailed;
use App\Domains\AI\Models\AiProvider;
use App\Domains\AI\Models\AiProviderCredential;
use App\Domains\AI\Services\ProviderRegistry;
use App\Domains\AI\Support\Capability;
use App\Domains\AI\Support\ErrorClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The four things this provider does differently, each proven against a
 * recorded shape.
 *
 * These are the reasons it needs its own adapter rather than the
 * OpenAI-compatible one, and each of them fails in a way that would only be
 * discovered on a real customer's first message.
 */
class AnthropicAdapterTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'sk-ant-fixture-KEYKEYKEY1234';

    private function provider(): AiProvider
    {
        $provider = AiProvider::create([
            'name' => 'Anthropic Fixture',
            'adapter_type' => AnthropicAdapter::KEY,
            'api_base_url' => 'https://api.anthropic.test/v1',
            'auth_method' => 'header',
            'status' => AiProvider::STATUS_ACTIVE,
        ]);

        AiProviderCredential::create([
            'provider_id' => $provider->getKey(),
            'label' => 'Key',
            'credential' => self::KEY,
        ]);

        return $provider->fresh();
    }

    private function adapter()
    {
        return app(ProviderRegistry::class)->for($this->provider());
    }

    private function reply(string $text = 'A recorded reply'): void
    {
        Http::fake(['*/messages' => Http::response([
            'id' => 'msg_fixture',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'fixture-model',
            'content' => [['type' => 'text', 'text' => $text]],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 31, 'output_tokens' => 7],
        ])]);
    }

    private function request(array $messages, ?int $maxTokens = null): ChatRequest
    {
        return new ChatRequest(
            modelIdentifier: 'fixture-model',
            messages: $messages,
            maxTokens: $maxTokens,
        );
    }

    // -- difference 1: the system prompt is not a message ---------------------

    public function test_the_system_prompt_is_lifted_out_of_the_message_list(): void
    {
        $this->reply();

        $this->adapter()->chat($this->request([
            ChatMessage::system('You are a careful assistant.'),
            ChatMessage::user('Hello'),
        ]));

        Http::assertSent(function ($request) {
            $body = $request->data();

            // Top level, not a message. Sent as a message it is rejected, and
            // every persona in this product would fail on every request.
            $this->assertSame('You are a careful assistant.', $body['system'] ?? null);

            foreach ($body['messages'] as $message) {
                $this->assertNotSame('system', $message['role']);
            }

            return true;
        });
    }

    public function test_a_conversation_with_no_system_prompt_sends_no_system_field(): void
    {
        $this->reply();

        $this->adapter()->chat($this->request([ChatMessage::user('Hello')]));

        Http::assertSent(fn ($request) => ! array_key_exists('system', $request->data()));
    }

    // -- difference 2: max_tokens is required ---------------------------------

    public function test_max_tokens_is_always_sent_even_when_the_caller_omits_it(): void
    {
        $this->reply();

        // The caller passing null is normal; the API rejects the request
        // without it, so the adapter must supply one.
        $this->adapter()->chat($this->request([ChatMessage::user('Hello')], maxTokens: null));

        Http::assertSent(function ($request) {
            $this->assertIsInt($request->data()['max_tokens'] ?? null);
            $this->assertGreaterThan(0, $request->data()['max_tokens']);

            return true;
        });
    }

    public function test_the_callers_ceiling_is_respected_when_it_gives_one(): void
    {
        $this->reply();

        $this->adapter()->chat($this->request([ChatMessage::user('Hello')], maxTokens: 128));

        Http::assertSent(fn ($request) => $request->data()['max_tokens'] === 128);
    }

    // -- difference 3: its own auth headers -----------------------------------

    public function test_it_authenticates_with_its_own_header_and_a_version(): void
    {
        $this->reply();

        $this->adapter()->chat($this->request([ChatMessage::user('Hello')]));

        Http::assertSent(function ($request) {
            // A bearer token is rejected here, and a missing version header
            // fails in a way that looks exactly like a bad key.
            $this->assertSame(self::KEY, $request->header('x-api-key')[0] ?? null);
            $this->assertSame(AnthropicAdapter::API_VERSION, $request->header('anthropic-version')[0] ?? null);
            $this->assertEmpty($request->header('Authorization'));

            return true;
        });
    }

    // -- difference 4: typed streaming events ---------------------------------

    public function test_streaming_reads_typed_events_and_stops_on_message_stop(): void
    {
        $events = [
            ['type' => 'message_start', 'message' => ['id' => 'msg_1']],
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Hello']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => ', world']],
            ['type' => 'content_block_stop', 'index' => 0],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn']],
            ['type' => 'message_stop'],
            // Anything after the stop event must never be yielded.
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'LEAKED']],
        ];

        Http::fake(['*/messages' => Http::response($this->sse($events))]);

        $fragments = iterator_to_array($this->adapter()->streamChat($this->request([ChatMessage::user('Hi')])));

        $this->assertSame('Hello, world', implode('', $fragments));
        $this->assertStringNotContainsString('LEAKED', implode('', $fragments));
    }

    public function test_thinking_and_tool_deltas_never_reach_the_customer(): void
    {
        Http::fake(['*/messages' => Http::response($this->sse([
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'thinking_delta', 'thinking' => 'Let me work through this…']],
            ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"query":']],
            ['type' => 'content_block_delta', 'index' => 2, 'delta' => ['type' => 'text_delta', 'text' => 'The answer.']],
            ['type' => 'message_stop'],
        ]))]);

        $out = implode('', iterator_to_array($this->adapter()->streamChat($this->request([ChatMessage::user('Hi')]))));

        // Reasoning and half-built tool arguments travel the same channel as
        // the reply. Forwarding them would put the model's internal working
        // into a customer's chat bubble.
        $this->assertSame('The answer.', $out);
    }

    public function test_an_error_arriving_mid_stream_is_raised_not_swallowed(): void
    {
        Http::fake(['*/messages' => Http::response($this->sse([
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Partial']],
            ['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']],
        ]))]);

        $this->expectException(ProviderFailed::class);

        // A 200 followed by a mid-stream failure. Ignoring it would end the
        // reply silently and leave a truncated answer with no explanation.
        iterator_to_array($this->adapter()->streamChat($this->request([ChatMessage::user('Hi')])));
    }

    // -- images ---------------------------------------------------------------

    public function test_an_image_becomes_a_base64_content_block_before_the_text(): void
    {
        $this->reply();

        $this->adapter()->chat($this->request([
            ChatMessage::user('What is this?', [[
                'type' => 'image',
                'mime' => 'image/png',
                'data' => base64_encode('not-a-real-png'),
            ]]),
        ]));

        Http::assertSent(function ($request) {
            $content = $request->data()['messages'][0]['content'];

            $this->assertSame('image', $content[0]['type']);
            $this->assertSame('base64', $content[0]['source']['type']);
            $this->assertSame('image/png', $content[0]['source']['media_type']);
            // The question comes after the picture: a prompt that arrives
            // first reads as if the picture is missing.
            $this->assertSame('text', $content[1]['type']);

            return true;
        });
    }

    public function test_a_message_with_no_attachment_sends_a_plain_string(): void
    {
        $this->reply();

        $this->adapter()->chat($this->request([ChatMessage::user('Just text')]));

        Http::assertSent(fn ($request) => $request->data()['messages'][0]['content'] === 'Just text');
    }

    // -- the reply ------------------------------------------------------------

    public function test_the_reply_is_normalised_into_azivs_own_shape(): void
    {
        $this->reply('Paris.');

        $response = $this->adapter()->chat($this->request([ChatMessage::user('Capital of France?')]));

        $this->assertSame('Paris.', $response->content);
        $this->assertSame(31, $response->usage->inputTokens);
        $this->assertSame(7, $response->usage->outputTokens);
        $this->assertSame('end_turn', $response->finishReason);
    }

    public function test_several_text_blocks_are_joined_and_other_blocks_ignored(): void
    {
        Http::fake(['*/messages' => Http::response([
            'model' => 'fixture-model',
            'content' => [
                ['type' => 'thinking', 'thinking' => 'internal reasoning'],
                ['type' => 'text', 'text' => 'First. '],
                ['type' => 'text', 'text' => 'Second.'],
            ],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 2],
        ])]);

        $response = $this->adapter()->chat($this->request([ChatMessage::user('Hi')]));

        $this->assertSame('First. Second.', $response->content);
        $this->assertStringNotContainsString('internal reasoning', $response->content);
    }

    // -- failures -------------------------------------------------------------

    public static function failures(): array
    {
        return [
            'rejected key' => [401, 'authentication_error', ErrorClass::AUTHENTICATION],
            'not entitled' => [403, 'permission_error', ErrorClass::AUTHORISATION],
            'too many requests' => [429, 'rate_limit_error', ErrorClass::RATE_LIMIT],
            'unknown model' => [404, 'not_found_error', ErrorClass::MODEL_NOT_FOUND],
            'too much context' => [413, 'request_too_large', ErrorClass::CONTEXT_TOO_LONG],
            'bad request' => [400, 'invalid_request_error', ErrorClass::INVALID_REQUEST],
            'busy' => [529, 'overloaded_error', ErrorClass::PROVIDER_ERROR],
            'their fault' => [500, 'api_error', ErrorClass::PROVIDER_ERROR],
        ];
    }

    #[DataProvider('failures')]
    public function test_each_failure_gets_its_own_class(int $status, string $type, string $expected): void
    {
        Http::fake(['*/messages' => Http::response([
            'type' => 'error',
            'error' => ['type' => $type, 'message' => 'something went wrong'],
        ], $status)]);

        try {
            $this->adapter()->chat($this->request([ChatMessage::user('Hi')]));
            $this->fail('Expected the failure to surface.');
        } catch (ProviderFailed $e) {
            $this->assertSame($expected, $e->errorClass);
        }
    }

    public function test_the_providers_own_error_text_never_crosses_the_boundary(): void
    {
        Http::fake(['*/messages' => Http::response([
            'type' => 'error',
            'error' => [
                'type' => 'invalid_request_error',
                // Several APIs echo the failing request back, and that request
                // carried the key.
                'message' => 'Request failed: x-api-key '.self::KEY.' rejected',
            ],
        ], 400)]);

        try {
            $this->adapter()->chat($this->request([ChatMessage::user('Hi')]));
            $this->fail('Expected the failure to surface.');
        } catch (ProviderFailed $e) {
            $this->assertStringNotContainsString(self::KEY, $e->getMessage());
            $this->assertStringNotContainsString('sk-ant', $e->getMessage());
        }
    }

    // -- discovery ------------------------------------------------------------

    public function test_the_catalog_is_read_from_the_provider_not_from_code(): void
    {
        Http::fake(['*/models*' => Http::response([
            'data' => [
                ['type' => 'model', 'id' => 'fixture-large', 'display_name' => 'Fixture Large', 'max_input_tokens' => 200000, 'max_tokens' => 64000],
                ['type' => 'model', 'id' => 'fixture-small', 'display_name' => 'Fixture Small'],
            ],
        ])]);

        $models = $this->adapter()->listModels();

        $this->assertCount(2, $models);
        $this->assertSame('fixture-large', $models[0]->identifier);
        $this->assertSame(200000, $models[0]->contextWindow);
        // Absent means "not stated" — never an invented number.
        $this->assertNull($models[1]->contextWindow);
        // Capabilities are not guessed from the identifier.
        $this->assertSame([Capability::CHAT, Capability::STREAMING], $models[0]->capabilities);
    }

    public function test_a_connection_test_costs_nothing_and_reports_latency(): void
    {
        Http::fake(['*/models*' => Http::response(['data' => [['id' => 'fixture-model']]])]);

        $result = $this->adapter()->testConnection();

        $this->assertTrue($result->success);
        // A read, never a generation: testing must not be able to spend money.
        Http::assertSent(fn ($request) => $request->method() === 'GET' && str_contains($request->url(), '/models'));
    }

    public function test_it_declares_the_contracts_the_router_looks_for(): void
    {
        $adapter = $this->adapter();

        $this->assertInstanceOf(SupportsChat::class, $adapter);
        $this->assertInstanceOf(SupportsStreaming::class, $adapter);
        $this->assertInstanceOf(SupportsModelDiscovery::class, $adapter);
        $this->assertContains(Capability::VISION, $adapter->capabilities());
    }

    /** @param array<int, array<string, mixed>> $events */
    private function sse(array $events): string
    {
        $body = '';

        foreach ($events as $event) {
            $body .= 'event: '.$event['type']."\n";
            $body .= 'data: '.json_encode($event)."\n\n";
        }

        return $body;
    }
}
