<?php

namespace Tests\Feature\AI;

use App\Domains\AI\Adapters\AnthropicAdapter;
use App\Domains\AI\Adapters\CustomHttpAdapter;
use App\Domains\AI\Adapters\GeminiAdapter;
use App\Domains\AI\Adapters\OpenAiAdapter;
use App\Domains\AI\Adapters\OpenAiCompatibleAdapter;
use App\Domains\AI\Contracts\ProviderAdapter;
use App\Domains\AI\Contracts\SupportsChat;
use App\Domains\AI\Contracts\SupportsStreaming;
use App\Domains\AI\DTO\ChatMessage;
use App\Domains\AI\DTO\ChatRequest;
use App\Domains\AI\Exceptions\ProviderFailed;
use App\Domains\AI\Models\AiProvider;
use App\Domains\AI\Models\AiProviderCredential;
use App\Domains\AI\Models\CustomProviderMapping;
use App\Domains\AI\Services\ProviderRegistry;
use App\Domains\AI\Support\Capability;
use App\Domains\AI\Support\ErrorClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * THE SAME CONTRACT, RUN AGAINST EVERY ADAPTER (Phase 7 gate).
 *
 * Each provider's wire shape is different — that is the entire reason the
 * adapter layer exists — so each has its own recorded fixture below. What is
 * IDENTICAL is everything above the boundary: Aziv AI's request goes in,
 * Aziv AI's response comes back, the same capabilities are declarable, and a
 * failure is a class rather than a provider's prose.
 *
 * A new adapter joins by adding one row to `adapters()`. If it cannot satisfy
 * these assertions it is not finished, and the build says so — which is what
 * stops "adding a provider" quietly becoming "adding a provider and a special
 * case somewhere upstream".
 */
class EveryAdapterContractTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'sk-contract-KEYKEYKEY1234';

    /**
     * Every shipped adapter, with the shape its provider actually returns.
     *
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: string}>
     */
    public static function adapters(): array
    {
        return [
            'OpenAI' => [
                OpenAiAdapter::KEY,
                [
                    'model' => 'fixture-model',
                    'choices' => [['message' => ['content' => 'Contract reply'], 'finish_reason' => 'stop']],
                    'usage' => ['prompt_tokens' => 11, 'completion_tokens' => 5],
                ],
                '*/chat/completions',
            ],
            'Anthropic' => [
                AnthropicAdapter::KEY,
                [
                    'model' => 'fixture-model',
                    'content' => [['type' => 'text', 'text' => 'Contract reply']],
                    'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 11, 'output_tokens' => 5],
                ],
                '*/messages',
            ],
            'Gemini' => [
                GeminiAdapter::KEY,
                [
                    'candidates' => [[
                        'content' => ['parts' => [['text' => 'Contract reply']]],
                        'finishReason' => 'STOP',
                    ]],
                    'usageMetadata' => ['promptTokenCount' => 11, 'candidatesTokenCount' => 5],
                ],
                '*generateContent*',
            ],
            // The one that serves DeepSeek, Mistral, Groq, OpenRouter,
            // Hugging Face and most of the rest of the market.
            'OpenAI-compatible' => [
                OpenAiCompatibleAdapter::KEY,
                [
                    'model' => 'fixture-model',
                    'choices' => [['message' => ['content' => 'Contract reply'], 'finish_reason' => 'stop']],
                    'usage' => ['prompt_tokens' => 11, 'completion_tokens' => 5],
                ],
                '*/chat/completions',
            ],
            'Custom API' => [
                CustomHttpAdapter::KEY,
                ['result' => ['output' => 'Contract reply'], 'meta' => ['in' => 11, 'out' => 5]],
                '*/generate',
            ],
        ];
    }

    private function provider(string $adapterType): AiProvider
    {
        $provider = AiProvider::create([
            'name' => 'Contract '.$adapterType,
            'adapter_type' => $adapterType,
            'api_base_url' => 'https://api.contract.test/v1',
            'auth_method' => $adapterType === AnthropicAdapter::KEY ? 'header' : 'bearer',
            'status' => AiProvider::STATUS_ACTIVE,
        ]);

        AiProviderCredential::create([
            'provider_id' => $provider->getKey(),
            'label' => 'Key',
            'credential' => self::KEY,
        ]);

        // The custom adapter is driven entirely from a mapping an
        // administrator writes — no code knows its shape.
        if ($adapterType === CustomHttpAdapter::KEY) {
            CustomProviderMapping::create([
                'provider_id' => $provider->getKey(),
                'capability' => Capability::CHAT,
                'http_method' => 'POST',
                'endpoint_path' => 'generate',
                // {{system}} is why this adapter can carry a persona at all:
                // a custom mapping decides WHERE the system prompt goes, and
                // an owner who leaves it out has a provider that silently
                // ignores every persona they configure.
                'request_template' => [
                    'model_name' => '{{model}}',
                    'input' => '{{prompt}}',
                    'instructions' => '{{system}}',
                ],
                'response_mapping' => [
                    'content' => 'result.output',
                    'input_tokens' => 'meta.in',
                    'output_tokens' => 'meta.out',
                ],
            ]);
        }

        return $provider->fresh();
    }

    private function adapter(string $adapterType): ProviderAdapter
    {
        return app(ProviderRegistry::class)->for($this->provider($adapterType));
    }

    private function request(): ChatRequest
    {
        return new ChatRequest(
            modelIdentifier: 'fixture-model',
            messages: [
                ChatMessage::system('You are helpful.'),
                ChatMessage::user('Say something'),
            ],
            maxTokens: 64,
        );
    }

    // -- the contract ---------------------------------------------------------

    #[DataProvider('adapters')]
    public function test_the_registry_resolves_it(string $adapterType): void
    {
        $this->assertInstanceOf(ProviderAdapter::class, $this->adapter($adapterType));
    }

    #[DataProvider('adapters')]
    public function test_it_declares_at_least_chat_and_can_answer_about_capabilities(string $adapterType): void
    {
        $adapter = $this->adapter($adapterType);

        $this->assertContains(Capability::CHAT, $adapter->capabilities());
        $this->assertTrue($adapter->supports(Capability::CHAT));
        // A capability it does not declare must answer false rather than
        // throwing — the router asks this of every candidate.
        $this->assertFalse($adapter->supports('a-capability-that-does-not-exist'));
    }

    #[DataProvider('adapters')]
    public function test_azivs_request_goes_in_and_azivs_response_comes_back(
        string $adapterType,
        array $fixture,
        string $pattern,
    ): void {
        Http::fake([$pattern => Http::response($fixture)]);

        $adapter = $this->adapter($adapterType);

        $this->assertInstanceOf(SupportsChat::class, $adapter);

        $response = $adapter->chat($this->request());

        // Identical on the way out, whatever the shape on the wire was.
        $this->assertSame('Contract reply', $response->content);
        $this->assertSame(11, $response->usage->inputTokens);
        $this->assertSame(5, $response->usage->outputTokens);
        $this->assertSame(16, $response->usage->totalTokens());
    }

    #[DataProvider('adapters')]
    public function test_the_system_prompt_reaches_the_provider_somehow(
        string $adapterType,
        array $fixture,
        string $pattern,
    ): void {
        Http::fake([$pattern => Http::response($fixture)]);

        $this->adapter($adapterType)->chat($this->request());

        Http::assertSent(function ($request) {
            // WHERE it goes is the adapter's business — a message, a top-level
            // field, a system_instruction block. THAT it goes is the contract:
            // a persona silently dropped is the failure nobody notices until a
            // customer gets an answer written in the wrong voice.
            return str_contains(json_encode($request->data()), 'You are helpful.');
        });
    }

    #[DataProvider('adapters')]
    public function test_a_rejected_key_is_classified_not_echoed(
        string $adapterType,
        array $fixture,
        string $pattern,
    ): void {
        Http::fake([$pattern => Http::response([
            'error' => [
                'type' => 'authentication_error',
                'code' => 'invalid_api_key',
                'status' => 'UNAUTHENTICATED',
                // Providers echo the failing request back, and it carried the
                // key. Not one of them may let it out.
                'message' => 'Invalid key '.self::KEY.' supplied',
            ],
        ], 401)]);

        try {
            $this->adapter($adapterType)->chat($this->request());
            $this->fail('Expected '.$adapterType.' to surface the failure.');
        } catch (ProviderFailed $e) {
            $this->assertSame(ErrorClass::AUTHENTICATION, $e->errorClass);
            $this->assertStringNotContainsString(self::KEY, $e->getMessage());
        }
    }

    #[DataProvider('adapters')]
    public function test_a_server_failure_is_classified_as_retryable(
        string $adapterType,
        array $fixture,
        string $pattern,
    ): void {
        Http::fake([$pattern => Http::response(['error' => ['message' => 'upstream exploded']], 503)]);

        try {
            $this->adapter($adapterType)->chat($this->request());
            $this->fail('Expected '.$adapterType.' to surface the failure.');
        } catch (ProviderFailed $e) {
            // The router's retry policy reads this. Misclassifying a transient
            // failure as permanent turns a blip into a lost customer.
            $this->assertTrue(
                ErrorClass::isRetryable($e->errorClass),
                $adapterType.' should treat a 503 as worth retrying, got '.$e->errorClass,
            );
        }
    }

    #[DataProvider('adapters')]
    public function test_a_missing_credential_fails_as_authentication_rather_than_crashing(string $adapterType): void
    {
        $provider = $this->provider($adapterType);
        $provider->credentials()->delete();

        try {
            app(ProviderRegistry::class)->for($provider->fresh())->chat($this->request());
            $this->fail('Expected '.$adapterType.' to refuse without a key.');
        } catch (ProviderFailed $e) {
            // An owner who has not pasted a key yet gets an answer they can
            // act on, not a stack trace.
            $this->assertSame(ErrorClass::AUTHENTICATION, $e->errorClass);
        }
    }

    /**
     * Streaming is optional, but an adapter that CLAIMS it must do it.
     */
    #[DataProvider('adapters')]
    public function test_an_adapter_claiming_streaming_declares_the_contract(string $adapterType): void
    {
        $adapter = $this->adapter($adapterType);

        if (! in_array(Capability::STREAMING, $adapter->capabilities(), true)) {
            $this->assertNotInstanceOf(SupportsStreaming::class, $adapter);
            $this->addToAssertionCount(1);

            return;
        }

        // R-01's fallback picks the non-streaming path by asking this
        // question. An adapter that answers wrongly either streams nothing or
        // never streams at all.
        $this->assertInstanceOf(SupportsStreaming::class, $adapter);
    }

    #[DataProvider('adapters')]
    public function test_a_connection_test_never_generates_anything_expensive(string $adapterType): void
    {
        Http::fake(['*' => Http::response(['data' => [], 'models' => [], 'choices' => [], 'result' => []])]);

        $result = $this->adapter($adapterType)->testConnection();

        // Whether it passes against a stub is not the point; that it is
        // BOUNDED is (§25). Nothing here may run an unbounded generation.
        $this->assertNotNull($result);

        Http::assertSent(function ($request) {
            $body = $request->data();
            $tokens = $body['max_tokens'] ?? $body['max_output_tokens']
                ?? data_get($body, 'generationConfig.maxOutputTokens');

            return $tokens === null || $tokens <= 32;
        });
    }
}
