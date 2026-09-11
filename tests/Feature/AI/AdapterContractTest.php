<?php

namespace Tests\Feature\AI;

use App\Domains\AI\Adapters\CustomHttpAdapter;
use App\Domains\AI\Adapters\GeminiAdapter;
use App\Domains\AI\Adapters\OpenAiAdapter;
use App\Domains\AI\Adapters\OpenAiCompatibleAdapter;
use App\Domains\AI\Contracts\SupportsChat;
use App\Domains\AI\Contracts\SupportsModelDiscovery;
use App\Domains\AI\Contracts\SupportsStreaming;
use App\Domains\AI\DTO\ChatMessage;
use App\Domains\AI\DTO\ChatRequest;
use App\Domains\AI\Models\AiProvider;
use App\Domains\AI\Models\AiProviderCredential;
use App\Domains\AI\Models\CustomProviderMapping;
use App\Domains\AI\Services\ProviderRegistry;
use App\Domains\AI\Support\Capability;
use App\Domains\AI\Support\ErrorClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Adapter contract tests against fixtures.
 *
 * No API key is needed and no real provider is called: the point is that the
 * TRANSLATION is correct — Aziv AI's format in, the provider's shape on the
 * wire, Aziv AI's format back — and that every failure mode is classified.
 */
class AdapterContractTest extends TestCase
{
    use RefreshDatabase;

    private function provider(string $adapter = OpenAiCompatibleAdapter::KEY): AiProvider
    {
        $provider = AiProvider::create([
            'name' => 'Fixture Provider',
            'adapter_type' => $adapter,
            'api_base_url' => 'https://api.fixture.test/v1',
            'status' => AiProvider::STATUS_ACTIVE,
        ]);

        AiProviderCredential::create([
            'provider_id' => $provider->getKey(),
            'label' => 'Key',
            'credential' => 'sk-fixture-KEYKEYKEYKEY1234',
        ]);

        return $provider->fresh();
    }

    private function adapter(string $type = OpenAiCompatibleAdapter::KEY)
    {
        return app(ProviderRegistry::class)->for($this->provider($type));
    }

    // -- the registry --------------------------------------------------------

    public function test_the_registry_resolves_every_shipped_adapter(): void
    {
        $registry = app(ProviderRegistry::class);

        foreach ([
            OpenAiAdapter::KEY,
            GeminiAdapter::KEY,
            OpenAiCompatibleAdapter::KEY,
            CustomHttpAdapter::KEY,
        ] as $key) {
            $this->assertTrue($registry->has($key), "{$key} is not registered.");
        }

        // Registration is the whole integration for a new adapter: nothing
        // else in the application refers to a provider by name.
        $this->assertCount(4, $registry->keys());
    }

    /**
     * A provider row can outlive the adapter that served it — a downgrade, a
     * removed integration. The Admin Panel has to stay usable so the owner can
     * see the problem, so this returns null rather than throwing.
     */
    public function test_an_unknown_adapter_type_returns_null_rather_than_throwing(): void
    {
        $provider = $this->provider();
        $provider->update(['adapter_type' => 'something_that_no_longer_exists']);

        $this->assertNull(app(ProviderRegistry::class)->for($provider->fresh()));
    }

    // -- the OpenAI-compatible shape ----------------------------------------

    public function test_it_declares_the_capabilities_it_can_serve(): void
    {
        $adapter = $this->adapter();

        $this->assertInstanceOf(SupportsChat::class, $adapter);
        $this->assertInstanceOf(SupportsStreaming::class, $adapter);
        $this->assertInstanceOf(SupportsModelDiscovery::class, $adapter);
        $this->assertTrue($adapter->supports(Capability::CHAT));
        $this->assertFalse($adapter->supports(Capability::TRANSCRIPTION));
    }

    public function test_a_chat_request_is_translated_and_the_reply_normalised(): void
    {
        Http::fake(['*/chat/completions' => Http::response([
            'model' => 'fixture-model-1',
            'choices' => [['message' => ['content' => 'Hello there'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 4],
        ])]);

        $response = $this->adapter()->chat(new ChatRequest(
            modelIdentifier: 'fixture-model-1',
            messages: [ChatMessage::system('Be brief.'), ChatMessage::user('Hi')],
            maxTokens: 50,
        ));

        // Aziv AI's format coming back out.
        $this->assertSame('Hello there', $response->content);
        $this->assertSame(12, $response->usage->inputTokens);
        $this->assertSame(4, $response->usage->outputTokens);
        $this->assertSame(16, $response->usage->totalTokens());
        $this->assertSame('stop', $response->finishReason);

        // The provider's shape going in.
        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->hasHeader('Authorization', 'Bearer sk-fixture-KEYKEYKEYKEY1234')
                && $body['model'] === 'fixture-model-1'
                && $body['messages'][0] === ['role' => 'system', 'content' => 'Be brief.']
                && $body['messages'][1] === ['role' => 'user', 'content' => 'Hi']
                && $body['max_tokens'] === 50;
        });
    }

    public function test_an_image_attachment_becomes_content_parts(): void
    {
        Http::fake(['*/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'A cat.']]],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2],
        ])]);

        $this->adapter()->chat(new ChatRequest(
            modelIdentifier: 'vision-model',
            messages: [ChatMessage::user('What is this?', [
                ['type' => 'image', 'url' => 'https://files.test/cat.png'],
            ])],
        ));

        Http::assertSent(function ($request) {
            $content = $request->data()['messages'][0]['content'];

            return is_array($content)
                && $content[0] === ['type' => 'text', 'text' => 'What is this?']
                && $content[1]['image_url']['url'] === 'https://files.test/cat.png';
        });
    }

    public function test_streaming_yields_text_fragments_and_stops_at_done(): void
    {
        $stream = "data: {\"choices\":[{\"delta\":{\"content\":\"Hel\"}}]}\n"
            ."data: {\"choices\":[{\"delta\":{\"content\":\"lo\"}}]}\n"
            ."data: {\"choices\":[{\"delta\":{}}]}\n"
            ."data: [DONE]\n"
            ."data: {\"choices\":[{\"delta\":{\"content\":\"after the end\"}}]}\n";

        Http::fake(['*/chat/completions' => Http::response($stream)]);

        $fragments = iterator_to_array($this->adapter()->streamChat(new ChatRequest(
            modelIdentifier: 'fixture-model-1',
            messages: [ChatMessage::user('Hi')],
            stream: true,
        )));

        $this->assertSame(['Hel', 'lo'], $fragments);
    }

    /** Rule 5: the catalog comes from the provider, never from a constant. */
    public function test_model_discovery_reads_the_providers_own_list(): void
    {
        Http::fake(['*/models' => Http::response(['data' => [
            ['id' => 'alpha-1', 'context_length' => 128000],
            ['id' => 'beta-2', 'name' => 'Beta Two'],
            ['id' => ''],            // malformed — must be skipped
            ['name' => 'no id'],     // malformed — must be skipped
        ]])]);

        $models = $this->adapter()->listModels();

        $this->assertCount(2, $models);
        $this->assertSame('alpha-1', $models[0]->identifier);
        $this->assertSame(128000, $models[0]->contextWindow);
        $this->assertSame('Beta Two', $models[1]->name());
    }

    public function test_a_successful_connection_test_reports_latency(): void
    {
        Http::fake(['*/models' => Http::response(['data' => [['id' => 'a'], ['id' => 'b']]])]);

        $result = $this->adapter()->testConnection();

        $this->assertTrue($result->success);
        $this->assertSame(2, $result->context['models_visible']);
        $this->assertGreaterThanOrEqual(0, $result->latencyMs);
    }

    // -- failure classification ---------------------------------------------

    public static function failures(): array
    {
        return [
            'bad key' => [401, [], ErrorClass::AUTHENTICATION],
            'not entitled' => [403, [], ErrorClass::AUTHORISATION],
            'too fast' => [429, [], ErrorClass::RATE_LIMIT],
            'provider down' => [503, [], ErrorClass::PROVIDER_ERROR],
            'no such model' => [404, ['error' => ['code' => 'model_not_found']], ErrorClass::MODEL_NOT_FOUND],
            'out of credit' => [400, ['error' => ['code' => 'insufficient_quota']], ErrorClass::QUOTA_EXCEEDED],
            'too long' => [400, ['error' => ['code' => 'context_length_exceeded']], ErrorClass::CONTEXT_TOO_LONG],
            'filtered' => [400, ['error' => ['code' => 'content_filter']], ErrorClass::CONTENT_FILTERED],
            'malformed' => [422, [], ErrorClass::INVALID_REQUEST],
        ];
    }

    #[DataProvider('failures')]
    public function test_each_failure_gets_its_own_class(int $status, array $body, string $expected): void
    {
        Http::fake(['*/models' => Http::response($body ?: null, $status)]);

        $result = $this->adapter()->testConnection();

        $this->assertFalse($result->success);
        $this->assertSame($expected, $result->errorClass);
        $this->assertSame($status, $result->httpStatus);
        // Every class carries a remedy a non-developer can act on.
        $this->assertNotEmpty($result->detail);
    }

    /**
     * The provider's own words never survive the boundary: several APIs echo
     * the failing request back, and that request carried a credential.
     */
    public function test_a_providers_error_text_is_discarded(): void
    {
        Http::fake(['*/models' => Http::response([
            'error' => [
                'code' => 'invalid_api_key',
                'message' => 'Incorrect API key provided: sk-fixture-KEYKEYKEYKEY1234. Check your key.',
            ],
        ], 401)]);

        $result = $this->adapter()->testConnection();

        $encoded = json_encode($result->toArray());

        $this->assertStringNotContainsString('sk-fixture', $encoded);
        $this->assertStringNotContainsString('Incorrect API key provided', $encoded);
        $this->assertSame(ErrorClass::AUTHENTICATION, $result->errorClass);
    }

    public function test_a_connection_failure_is_distinguished_from_a_timeout(): void
    {
        Http::fake(fn () => throw new ConnectionException('Could not resolve host'));

        $result = $this->adapter()->testConnection();

        $this->assertSame(ErrorClass::NETWORK, $result->errorClass);
        // A shared host blocking outbound HTTPS is the single most common
        // cause, so the remedy points there.
        $this->assertStringContainsString('outbound HTTPS', $result->detail);
    }

    public function test_a_missing_key_fails_as_authentication_not_as_a_crash(): void
    {
        $provider = AiProvider::create([
            'name' => 'Keyless',
            'adapter_type' => OpenAiCompatibleAdapter::KEY,
            'api_base_url' => 'https://api.fixture.test/v1',
            'status' => AiProvider::STATUS_ACTIVE,
        ]);

        $result = app(ProviderRegistry::class)->for($provider)->testConnection();

        $this->assertFalse($result->success);
        $this->assertSame(ErrorClass::AUTHENTICATION, $result->errorClass);
    }

    // -- the custom adapter --------------------------------------------------

    public function test_a_custom_api_is_driven_entirely_from_its_mapping(): void
    {
        $provider = $this->provider(CustomHttpAdapter::KEY);

        CustomProviderMapping::create([
            'provider_id' => $provider->getKey(),
            'capability' => Capability::CHAT,
            'http_method' => 'POST',
            'endpoint_path' => 'generate',
            'request_template' => [
                'model_name' => '{{model}}',
                'input' => ['text' => '{{prompt}}'],
                'settings' => ['limit' => '{{max_tokens}}'],
            ],
            'response_mapping' => [
                'content' => 'result.output',
                'input_tokens' => 'meta.in',
                'output_tokens' => 'meta.out',
            ],
        ]);

        Http::fake(['*/generate' => Http::response([
            'result' => ['output' => 'Custom reply'],
            'meta' => ['in' => 7, 'out' => 3],
        ])]);

        $response = app(ProviderRegistry::class)->for($provider->fresh())->chat(new ChatRequest(
            modelIdentifier: 'custom-model',
            messages: [ChatMessage::user('Say something')],
            maxTokens: 99,
        ));

        $this->assertSame('Custom reply', $response->content);
        $this->assertSame(7, $response->usage->inputTokens);
        $this->assertSame(3, $response->usage->outputTokens);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $body['model_name'] === 'custom-model'
                && $body['input']['text'] === 'Say something'
                // A standalone placeholder keeps its real type, so a numeric
                // setting arrives as a number rather than as "99".
                && $body['settings']['limit'] === 99;
        });
    }

    /**
     * A placeholder standing alone keeps its real type, so {{messages}} can
     * become a list rather than the string "Array".
     */
    public function test_a_standalone_placeholder_keeps_its_type(): void
    {
        $provider = $this->provider(CustomHttpAdapter::KEY);

        CustomProviderMapping::create([
            'provider_id' => $provider->getKey(),
            'capability' => Capability::CHAT,
            'endpoint_path' => 'chat',
            'request_template' => ['conversation' => '{{messages}}'],
            'response_mapping' => ['content' => 'text'],
        ]);

        Http::fake(['*/chat' => Http::response(['text' => 'ok'])]);

        app(ProviderRegistry::class)->for($provider->fresh())->chat(new ChatRequest(
            modelIdentifier: 'm',
            messages: [ChatMessage::user('One'), ChatMessage::assistant('Two')],
        ));

        Http::assertSent(function ($request) {
            $conversation = $request->data()['conversation'];

            return is_array($conversation)
                && count($conversation) === 2
                && $conversation[0]['content'] === 'One';
        });
    }

    /**
     * The header template is an ordinary JSON column, so a key must never be
     * typed into it. `{{credential}}` pulls it from the encrypted store at
     * call time instead.
     */
    public function test_the_credential_placeholder_is_filled_from_the_encrypted_store(): void
    {
        $provider = $this->provider(CustomHttpAdapter::KEY);

        CustomProviderMapping::create([
            'provider_id' => $provider->getKey(),
            'capability' => Capability::CHAT,
            'endpoint_path' => 'chat',
            'request_template' => ['q' => '{{prompt}}'],
            'response_mapping' => ['content' => 'text'],
            'headers_template' => ['X-Custom-Auth' => 'Token '.CustomHttpAdapter::CREDENTIAL_PLACEHOLDER],
        ]);

        Http::fake(['*/chat' => Http::response(['text' => 'ok'])]);

        app(ProviderRegistry::class)->for($provider->fresh())->chat(new ChatRequest(
            modelIdentifier: 'm',
            messages: [ChatMessage::user('Hi')],
        ));

        Http::assertSent(fn ($request) => $request->hasHeader('X-Custom-Auth', 'Token sk-fixture-KEYKEYKEYKEY1234'));

        // And the stored template still holds only the placeholder.
        $this->assertStringNotContainsString(
            'sk-fixture',
            json_encode(CustomProviderMapping::first()->headers_template),
        );
    }

    public function test_a_custom_provider_without_a_mapping_says_so_rather_than_failing_obscurely(): void
    {
        $provider = $this->provider(CustomHttpAdapter::KEY);

        $result = app(ProviderRegistry::class)->for($provider)->testConnection();

        $this->assertFalse($result->success);
        $this->assertSame(ErrorClass::INVALID_REQUEST, $result->errorClass);
    }

    public function test_a_template_is_never_evaluated_as_code(): void
    {
        $provider = $this->provider(CustomHttpAdapter::KEY);

        CustomProviderMapping::create([
            'provider_id' => $provider->getKey(),
            'capability' => Capability::CHAT,
            'endpoint_path' => 'chat',
            // A template is configuration written by a person. It must never
            // become a way to execute something.
            'request_template' => ['q' => '{{ phpinfo() }}', 'r' => '<?php echo 1; ?>'],
            'response_mapping' => ['content' => 'text'],
        ]);

        Http::fake(['*/chat' => Http::response(['text' => 'ok'])]);

        app(ProviderRegistry::class)->for($provider->fresh())->chat(new ChatRequest(
            modelIdentifier: 'm',
            messages: [ChatMessage::user('Hi')],
        ));

        Http::assertSent(function ($request) {
            $body = $request->data();

            // Passed through as literal text, unchanged and unevaluated.
            return $body['q'] === '{{ phpinfo() }}' && $body['r'] === '<?php echo 1; ?>';
        });
    }
}
