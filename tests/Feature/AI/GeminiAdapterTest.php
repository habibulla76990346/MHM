<?php

namespace Tests\Feature\AI;

use App\Domains\AI\Adapters\GeminiAdapter;
use App\Domains\AI\Adapters\OpenAiAdapter;
use App\Domains\AI\DTO\ChatMessage;
use App\Domains\AI\DTO\ChatRequest;
use App\Domains\AI\Models\AiProvider;
use App\Domains\AI\Models\AiProviderCredential;
use App\Domains\AI\Services\ProviderRegistry;
use App\Domains\AI\Support\Capability;
use App\Domains\AI\Support\ErrorClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Gemini is the adapter that justifies the architecture: it disagrees with
 * OpenAI about the message shape, the assistant's role name, where the system
 * prompt lives, how the key is sent, where the model identifier goes, and how
 * streaming is framed.
 *
 * Every one of those differences must be contained inside the adapter.
 */
class GeminiAdapterTest extends TestCase
{
    use RefreshDatabase;

    private function gemini(): GeminiAdapter
    {
        $provider = AiProvider::create([
            'name' => 'Gemini',
            'slug' => 'gemini',
            'adapter_type' => GeminiAdapter::KEY,
            'api_base_url' => GeminiAdapter::DEFAULT_BASE_URL,
            // The key goes in the query string, not a header.
            'auth_method' => 'query',
            'status' => AiProvider::STATUS_ACTIVE,
        ]);

        AiProviderCredential::create([
            'provider_id' => $provider->getKey(),
            'label' => 'Key',
            'credential' => 'AIza-fixture-KEYKEYKEY7777',
        ]);

        return app(ProviderRegistry::class)->for($provider->fresh());
    }

    public function test_it_sends_geminis_shape_not_openais(): void
    {
        Http::fake(['*:generateContent*' => Http::response([
            'candidates' => [[
                'content' => ['parts' => [['text' => 'Bonjour']]],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => ['promptTokenCount' => 8, 'candidatesTokenCount' => 2],
        ])]);

        $response = $this->gemini()->chat(new ChatRequest(
            modelIdentifier: 'gemini-test-model',
            messages: [
                ChatMessage::system('Answer in French.'),
                ChatMessage::user('Hello'),
                ChatMessage::assistant('Salut'),
                ChatMessage::user('Again'),
            ],
            maxTokens: 100,
        ));

        $this->assertSame('Bonjour', $response->content);
        $this->assertSame(8, $response->usage->inputTokens);
        $this->assertSame(2, $response->usage->outputTokens);
        $this->assertSame('stop', $response->finishReason);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return
                // The system prompt is lifted OUT of the message list.
                $body['systemInstruction']['parts'][0]['text'] === 'Answer in French.'
                // Messages are `contents` with `parts`.
                && $body['contents'][0]['parts'][0]['text'] === 'Hello'
                // The assistant's role is `model`, not `assistant`.
                && $body['contents'][1]['role'] === 'model'
                && $body['contents'][2]['role'] === 'user'
                // Generation settings live under generationConfig.
                && $body['generationConfig']['maxOutputTokens'] === 100
                // The model identifier is in the PATH, not the body.
                && str_contains($request->url(), 'models/gemini-test-model:generateContent')
                && ! isset($body['model'])
                // The key is a query parameter.
                && str_contains($request->url(), 'key=AIza-fixture-KEYKEYKEY7777');
        });
    }

    public function test_an_image_is_sent_as_inline_bytes_not_a_url(): void
    {
        Http::fake(['*:generateContent*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => 'A cat']]]]],
        ])]);

        $this->gemini()->chat(new ChatRequest(
            modelIdentifier: 'gemini-vision',
            messages: [ChatMessage::user('What is this?', [[
                'type' => 'image',
                'mime' => 'image/png',
                'data' => base64_encode('not-really-an-image'),
            ]])],
        ));

        Http::assertSent(function ($request) {
            $parts = $request->data()['contents'][0]['parts'];

            // OpenAI takes a URL; Gemini takes the bytes.
            return $parts[0]['text'] === 'What is this?'
                && $parts[1]['inlineData']['mimeType'] === 'image/png'
                && $parts[1]['inlineData']['data'] === base64_encode('not-really-an-image');
        });
    }

    public function test_streaming_reads_geminis_frames(): void
    {
        $body = '';

        foreach (['Bon', 'jour', ' monde'] as $fragment) {
            $body .= 'data: '.json_encode([
                'candidates' => [['content' => ['parts' => [['text' => $fragment]]]]],
            ])."\n";
        }

        Http::fake(['*:streamGenerateContent*' => Http::response($body)]);

        $fragments = iterator_to_array($this->gemini()->streamChat(new ChatRequest(
            modelIdentifier: 'gemini-test-model',
            messages: [ChatMessage::user('Hi')],
            stream: true,
        )));

        $this->assertSame(['Bon', 'jour', ' monde'], $fragments);
    }

    /** Gemini DOES publish what each model supports, so it is read, not guessed. */
    public function test_model_discovery_reads_declared_capabilities(): void
    {
        Http::fake(['*/models*' => Http::response(['models' => [
            [
                'name' => 'models/gemini-chatty',
                'displayName' => 'Gemini Chatty',
                'inputTokenLimit' => 1000000,
                'outputTokenLimit' => 8192,
                'supportedGenerationMethods' => ['generateContent', 'streamGenerateContent'],
            ],
            [
                'name' => 'models/gemini-embed',
                'displayName' => 'Gemini Embed',
                // Not a chat model, so it must not reach the chat catalog.
                'supportedGenerationMethods' => ['embedContent'],
            ],
        ]])]);

        $models = $this->gemini()->listModels();

        $this->assertCount(1, $models);
        // The "models/" prefix is stripped: the identifier is what goes on the
        // wire everywhere else.
        $this->assertSame('gemini-chatty', $models[0]->identifier);
        $this->assertSame(1000000, $models[0]->contextWindow);
        $this->assertContains(Capability::STREAMING, $models[0]->capabilities);
    }

    /**
     * Gemini reports a rejected key as HTTP 400 with a machine-readable
     * reason, where most APIs use 401. Without this, an owner with an expired
     * key would be told to check their settings instead of their key.
     */
    public function test_a_rejected_key_is_classified_as_authentication_despite_the_400(): void
    {
        Http::fake(['*/models*' => Http::response([
            'error' => [
                'code' => 400,
                'status' => 'INVALID_ARGUMENT',
                'message' => 'API key not valid. Please pass a valid API key.',
                'details' => [['reason' => 'API_KEY_INVALID']],
            ],
        ], 400)]);

        $result = $this->gemini()->testConnection();

        $this->assertFalse($result->success);
        $this->assertSame(ErrorClass::AUTHENTICATION, $result->errorClass);
        $this->assertStringContainsString('API key', $result->detail);
    }

    public function test_a_permission_denial_is_distinguished_from_a_bad_key(): void
    {
        Http::fake(['*/models*' => Http::response([
            'error' => ['status' => 'PERMISSION_DENIED'],
        ], 403)]);

        // Different problem, different remedy: the key is fine, the account
        // is not entitled.
        $this->assertSame(ErrorClass::AUTHORISATION, $this->gemini()->testConnection()->errorClass);
    }

    public function test_a_safety_block_is_reported_as_a_filter_not_a_fault(): void
    {
        Http::fake(['*:generateContent*' => Http::response([
            'candidates' => [['content' => ['parts' => []], 'finishReason' => 'SAFETY']],
        ])]);

        $response = $this->gemini()->chat(new ChatRequest(
            modelIdentifier: 'gemini-test-model',
            messages: [ChatMessage::user('Something')],
        ));

        // Gemini's vocabulary translated into Aziv AI's, so the chat system
        // does not have to learn a second set of words.
        $this->assertSame('content_filter', $response->finishReason);
    }

    // -- OpenAI's own adapter -------------------------------------------------

    public function test_the_openai_adapter_classifies_single_purpose_models_rather_than_dropping_them(): void
    {
        $provider = AiProvider::create([
            'name' => 'OpenAI', 'slug' => 'openai',
            'adapter_type' => OpenAiAdapter::KEY,
            'api_base_url' => OpenAiAdapter::DEFAULT_BASE_URL,
            'status' => AiProvider::STATUS_ACTIVE,
        ]);

        AiProviderCredential::create([
            'provider_id' => $provider->getKey(),
            'label' => 'Key', 'credential' => 'sk-fixture-OPENAIKEY8888',
        ]);

        Http::fake(['*/models' => Http::response(['data' => [
            ['id' => 'some-chat-model'],
            ['id' => 'text-embedding-3-large'],
            ['id' => 'whisper-1'],
            ['id' => 'dall-e-3'],
            ['id' => 'omni-moderation-latest'],
        ]])]);

        $models = app(ProviderRegistry::class)->for($provider->fresh())->listModels();

        $byIdentifier = collect($models)->keyBy('identifier');

        // STILL DROPPED: families that cannot serve any capability Aziv AI
        // routes. Importing them fills an owner's catalog with entries that
        // fail the moment anyone selects one.
        $this->assertNull($byIdentifier->get('omni-moderation-latest'));

        // NO LONGER DROPPED. Until Phase 8 every non-chat family was declined,
        // which was right when chat was the only capability and became wrong
        // the moment knowledge bases needed something to embed with: a catalog
        // with no embedding model in it leaves the router nothing to choose.
        // They are imported and CLASSIFIED instead — which is what the
        // capability system exists for.
        $this->assertSame(
            [Capability::CHAT, Capability::STREAMING],
            $byIdentifier->get('some-chat-model')->capabilities,
        );
        $this->assertSame([Capability::EMBEDDINGS], $byIdentifier->get('text-embedding-3-large')->capabilities);
        $this->assertSame([Capability::TRANSCRIPTION], $byIdentifier->get('whisper-1')->capabilities);
        $this->assertSame([Capability::IMAGE_GENERATION], $byIdentifier->get('dall-e-3')->capabilities);

        // And the classification is what keeps the router honest: an
        // embeddings endpoint has no reply to give, so it must never be
        // offered a conversation.
        $this->assertNotContains(Capability::CHAT, $byIdentifier->get('text-embedding-3-large')->capabilities);
    }
}
