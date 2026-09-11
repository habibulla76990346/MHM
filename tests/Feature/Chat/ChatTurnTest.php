<?php

namespace Tests\Feature\Chat;

use App\Domains\AI\Adapters\OpenAiAdapter;
use App\Domains\AI\Exceptions\ProviderFailed;
use App\Domains\AI\Models\AiModel;
use App\Domains\AI\Models\AiModelCapability;
use App\Domains\AI\Models\AiProvider;
use App\Domains\AI\Models\AiProviderCredential;
use App\Domains\AI\Models\ProviderHealthLog;
use App\Domains\AI\Support\Capability;
use App\Domains\AI\Support\ErrorClass;
use App\Domains\Chat\Models\Conversation;
use App\Domains\Chat\Models\Message;
use App\Domains\Chat\Models\Persona;
use App\Domains\Chat\Services\ChatService;
use App\Domains\Chat\Services\ContextBuilder;
use App\Domains\Chat\Services\ConversationService;
use App\Domains\Chat\Support\ChatRefused;
use App\Domains\Security\Services\PermissionRegistry;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Phase 4 gate: streamed response · stop settles partial usage ·
 * regenerate keeps history · context limits · rate limits · non-streaming
 * fallback.
 */
class ChatTurnTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private AiModel $model;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::factory()->create();
        $this->user->assignRole(PermissionRegistry::CUSTOMER);
        $this->user = $this->user->fresh();

        $provider = AiProvider::create([
            'name' => 'Test OpenAI',
            'adapter_type' => OpenAiAdapter::KEY,
            'api_base_url' => 'https://api.chat.test/v1',
            'status' => AiProvider::STATUS_ACTIVE,
        ]);

        AiProviderCredential::create([
            'provider_id' => $provider->getKey(),
            'label' => 'Key',
            'credential' => 'sk-chat-KEYKEYKEYKEY5555',
        ]);

        $this->model = AiModel::create([
            'provider_id' => $provider->getKey(),
            'model_identifier' => 'test-chat-model',
            'display_name' => 'Test Chat Model',
            'is_enabled' => true,
            'context_window' => 32000,
            'max_output_tokens' => 4096,
        ]);

        AiModelCapability::syncForModel($this->model, [Capability::CHAT, Capability::STREAMING]);
    }

    private function chat(): ChatService
    {
        return app(ChatService::class);
    }

    private function conversation(): Conversation
    {
        return app(ConversationService::class)->start($this->user);
    }

    /**
     * What the fake provider will reply next.
     *
     * Held as state and served by ONE stub: a second Http::fake() for the same
     * pattern does not replace the first — Laravel keeps the first match — so
     * re-faking to change the answer silently returns the original one. That
     * trap already made a Phase 3 test pass vacuously.
     */
    private ?string $reply = null;

    private ?array $streamFragments = null;

    private function installProviderStub(): void
    {
        Http::fake(['*/chat/completions' => function () {
            if ($this->streamFragments !== null) {
                $body = '';

                foreach ($this->streamFragments as $fragment) {
                    $body .= 'data: '.json_encode(['choices' => [['delta' => ['content' => $fragment]]]])."\n";
                }

                return Http::response($body."data: [DONE]\n");
            }

            return Http::response([
                'model' => 'test-chat-model',
                'choices' => [['message' => ['content' => $this->reply ?? 'Hello from the model'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 11, 'completion_tokens' => 5],
            ]);
        }]);
    }

    private function fakeReply(string $text = 'Hello from the model'): void
    {
        $this->reply = $text;
        $this->streamFragments = null;
        $this->installProviderStub();
    }

    // -- a turn ---------------------------------------------------------------

    public function test_a_turn_saves_the_question_before_calling_the_provider(): void
    {
        $conversation = $this->conversation();

        // No Http::fake at all — the provider call would fail. The customer's
        // message must survive that.
        $turn = $this->chat()->beginTurn($conversation, 'What is the capital of France?');

        $this->assertSame(Message::STATUS_COMPLETE, $turn['user']->status);
        $this->assertSame('What is the capital of France?', $turn['user']->content);
        // The answer row exists immediately, so the UI has something to attach
        // a stream to and a crash leaves a visible state.
        $this->assertSame(Message::STATUS_PENDING, $turn['assistant']->status);
        $this->assertSame($this->model->getKey(), $turn['assistant']->model_id);
    }

    public function test_the_first_message_names_the_conversation(): void
    {
        $conversation = $this->conversation();
        $this->assertNull($conversation->title);

        $this->chat()->beginTurn($conversation, 'Explain quantum tunnelling in simple terms');

        // Not an AI call: that would double what a chat costs for something
        // the first few words already answer.
        $this->assertStringContainsString('Explain quantum tunnelling', $conversation->fresh()->title);
        Http::assertNothingSent();
    }

    public function test_a_complete_turn_settles_content_usage_and_health(): void
    {
        $this->fakeReply('Paris.');

        $conversation = $this->conversation();
        $turn = $this->chat()->beginTurn($conversation, 'Capital of France?');

        $content = $this->chat()->complete($turn['assistant']);

        $assistant = $turn['assistant']->fresh();

        $this->assertSame('Paris.', $content);
        $this->assertSame(Message::STATUS_COMPLETE, $assistant->status);
        $this->assertSame(11, $assistant->input_tokens);
        $this->assertSame(5, $assistant->output_tokens);
        $this->assertNotNull($assistant->finished_at);

        // Every call feeds provider health, which Phase 5's router reads.
        $this->assertSame(1, ProviderHealthLog::where('success', true)->count());
    }

    // -- streaming ------------------------------------------------------------

    private function fakeStream(array $fragments): void
    {
        $this->streamFragments = $fragments;
        $this->installProviderStub();
    }

    public function test_a_streamed_reply_yields_fragments_and_settles_complete(): void
    {
        $this->fakeStream(['Hel', 'lo ', 'there']);

        $conversation = $this->conversation();
        $turn = $this->chat()->beginTurn($conversation, 'Say hello');

        $fragments = iterator_to_array($this->chat()->stream($turn['assistant']));

        $this->assertSame(['Hel', 'lo ', 'there'], $fragments);

        $assistant = $turn['assistant']->fresh();
        $this->assertSame('Hello there', $assistant->content);
        $this->assertSame(Message::STATUS_COMPLETE, $assistant->status);
        $this->assertGreaterThan(0, $assistant->output_tokens);
    }

    /**
     * THE GATE: stop halts the stream and SETTLES the partial reply. A message
     * left in `streaming` forever is worse than one marked stopped, and the
     * text that arrived was produced and paid for.
     */
    public function test_stopping_keeps_what_arrived_and_settles_the_message(): void
    {
        $this->fakeStream(['One ', 'Two ', 'Three ', 'Four']);

        $conversation = $this->conversation();
        $turn = $this->chat()->beginTurn($conversation, 'Count');
        $assistant = $turn['assistant'];

        $collected = [];

        foreach ($this->chat()->stream($assistant) as $fragment) {
            $collected[] = $fragment;

            // The customer presses stop after the first fragment.
            if (count($collected) === 1) {
                $this->chat()->requestStop($assistant);
            }
        }

        $settled = $assistant->fresh();

        $this->assertSame(['One '], $collected);
        $this->assertSame(Message::STATUS_STOPPED, $settled->status);
        $this->assertSame('One ', $settled->content);
        $this->assertGreaterThan(0, $settled->output_tokens);
        $this->assertNotNull($settled->finished_at);
        // The flag is cleared, or the next reply would stop instantly.
        $this->assertFalse($this->chat()->shouldStop($settled));
    }

    public function test_partial_text_survives_a_dropped_connection(): void
    {
        $this->fakeStream(['Partial ', 'answer']);

        $conversation = $this->conversation();
        $turn = $this->chat()->beginTurn($conversation, 'Go');

        $generator = $this->chat()->stream($turn['assistant']);
        $generator->current();   // consume only the first fragment

        // Persisted as it goes, so an abandoned stream leaves the customer
        // with what arrived rather than with nothing.
        $this->assertSame('Partial ', $turn['assistant']->fresh()->content);
    }

    /** Risk R-01: the platform degrades to a single response, it does not fail. */
    public function test_the_non_streaming_fallback_produces_the_same_answer(): void
    {
        $this->fakeReply('Same answer either way.');

        $conversation = $this->conversation();
        $turn = $this->chat()->beginTurn($conversation, 'Anything');

        $content = $this->chat()->complete($turn['assistant']);

        $this->assertSame('Same answer either way.', $content);
        $this->assertSame(Message::STATUS_COMPLETE, $turn['assistant']->fresh()->status);
    }

    public function test_a_provider_failure_settles_the_message_with_a_class_not_a_message(): void
    {
        Http::fake(['*/chat/completions' => fn () => Http::response([
            'error' => ['code' => 'invalid_api_key', 'message' => 'Bad key sk-chat-KEYKEYKEYKEY5555'],
        ], 401)]);

        $conversation = $this->conversation();
        $turn = $this->chat()->beginTurn($conversation, 'Hello');

        try {
            $this->chat()->complete($turn['assistant']);
            $this->fail('Expected the provider failure to surface.');
        } catch (ProviderFailed $e) {
            $this->assertSame(ErrorClass::AUTHENTICATION, $e->errorClass);
        }

        $assistant = $turn['assistant']->fresh();

        $this->assertSame(Message::STATUS_FAILED, $assistant->status);
        $this->assertSame(ErrorClass::AUTHENTICATION, $assistant->error_class);
        // The provider's words — which contained the key — are nowhere.
        $this->assertStringNotContainsString('sk-chat', json_encode($assistant->toArray()));
    }

    // -- regeneration ---------------------------------------------------------

    /** §15: regenerate must PRESERVE the original answer. */
    public function test_regenerating_keeps_the_original_and_shows_the_replacement(): void
    {
        $this->fakeReply('First answer.');

        $conversation = $this->conversation();
        $turn = $this->chat()->beginTurn($conversation, 'Tell me something');
        $this->chat()->complete($turn['assistant']);

        $original = $turn['assistant']->fresh();

        $this->fakeReply('Second answer.');

        $again = $this->chat()->beginRegeneration($original);
        $this->chat()->complete($again['assistant']);

        // Both rows exist.
        $this->assertSame(3, Message::count());
        $this->assertSame('First answer.', $original->fresh()->content);
        $this->assertSame($original->getKey(), $again['assistant']->regenerated_from_id);

        // Only the replacement is shown.
        $visible = $conversation->visibleMessages()->pluck('content')->all();
        $this->assertContains('Second answer.', $visible);
        $this->assertNotContains('First answer.', $visible);
    }

    /**
     * Regenerating must send the conversation AS IT WAS — the answer being
     * replaced must not be fed back in as context, or the model is asked to
     * improve on something it can see.
     */
    public function test_regeneration_context_excludes_the_answer_being_replaced(): void
    {
        $this->fakeReply('First answer.');

        $conversation = $this->conversation();
        $turn = $this->chat()->beginTurn($conversation, 'The question');
        $this->chat()->complete($turn['assistant']);

        $this->fakeReply('Second answer.');

        $again = $this->chat()->beginRegeneration($turn['assistant']->fresh());
        $this->chat()->complete($again['assistant']);

        Http::assertSent(function ($request) {
            $contents = json_encode($request->data()['messages']);

            return str_contains($contents, 'The question')
                && ! str_contains($contents, 'First answer.');
        });
    }

    // -- limits ---------------------------------------------------------------

    public function test_an_empty_message_is_refused(): void
    {
        $this->expectException(ChatRefused::class);

        $this->chat()->beginTurn($this->conversation(), '   ');
    }

    public function test_an_over_long_message_is_refused_with_the_limit(): void
    {
        settings()->set('chat.max_message_length', 120);

        $this->expectException(ChatRefused::class);
        $this->expectExceptionMessage('120');

        $this->chat()->beginTurn($this->conversation(), str_repeat('a', 121));
    }

    public function test_a_second_reply_cannot_start_while_one_is_in_flight(): void
    {
        $conversation = $this->conversation();
        $this->chat()->beginTurn($conversation, 'First');

        // Two concurrent streams would interleave into one thread and bill
        // twice for one question.
        $this->expectException(ChatRefused::class);

        $this->chat()->beginTurn($conversation, 'Second');
    }

    public function test_the_rate_limit_protects_the_provider_bill(): void
    {
        settings()->set('chat.rate_limit_per_minute', 2);
        $this->fakeReply();

        for ($i = 0; $i < 2; $i++) {
            $conversation = $this->conversation();
            $turn = $this->chat()->beginTurn($conversation, "Message {$i}");
            $this->chat()->complete($turn['assistant']);
        }

        $this->expectException(ChatRefused::class);
        $this->expectExceptionMessage('very quickly');

        $this->chat()->beginTurn($this->conversation(), 'One too many');
    }

    public function test_a_turn_fails_clearly_when_no_model_is_available(): void
    {
        $this->model->update(['is_enabled' => false]);

        $this->expectException(ChatRefused::class);
        $this->expectExceptionMessage('No AI model is available');

        $this->chat()->beginTurn($this->conversation(), 'Hello');
    }

    // -- context --------------------------------------------------------------

    public function test_history_is_capped_by_the_message_limit(): void
    {
        settings()->set('chat.context_message_limit', 4);
        $this->fakeReply('ok');

        $conversation = $this->conversation();

        for ($i = 1; $i <= 6; $i++) {
            $turn = $this->chat()->beginTurn($conversation, "Question {$i}");
            $this->chat()->complete($turn['assistant']);
        }

        $history = app(ContextBuilder::class)->history($conversation->fresh());

        $this->assertCount(4, $history);
        // The NEWEST messages are the ones kept.
        $this->assertStringContainsString('Question 6', collect($history)->pluck('content')->implode(' '));
        $this->assertStringNotContainsString('Question 1', collect($history)->pluck('content')->implode(' '));
    }

    public function test_the_token_budget_trims_history_further_than_the_message_count(): void
    {
        settings()->set('chat.context_message_limit', 50);
        settings()->set('chat.context_token_budget', 600);
        $this->fakeReply('ok');

        $conversation = $this->conversation();

        // Twenty short messages and twenty pasted documents are not the same
        // amount of money, which is why a count alone is not enough.
        for ($i = 1; $i <= 5; $i++) {
            $turn = $this->chat()->beginTurn($conversation, str_repeat('word ', 200));
            $this->chat()->complete($turn['assistant']);
        }

        $built = app(ContextBuilder::class)->build($conversation->fresh(), $this->model);

        $this->assertLessThan(10, count($built));
    }

    public function test_the_budget_never_exceeds_the_models_own_context_window(): void
    {
        settings()->set('chat.context_token_budget', 500_000);
        settings()->set('chat.max_output_tokens', 2000);

        $budget = app(ContextBuilder::class)->tokenBudget($this->model);

        // Room must be left for the reply: a context that exactly fills the
        // window leaves the model nowhere to answer.
        $this->assertSame(30000, $budget);
    }

    public function test_a_persona_becomes_the_system_prompt(): void
    {
        $persona = Persona::create([
            'name' => 'Support agent',
            'system_prompt' => 'You are a concise support agent.',
            'is_default' => true,
            'status' => 'active',
        ]);

        $this->fakeReply('ok');

        $conversation = app(ConversationService::class)->start($this->user, $persona->getKey());
        $turn = $this->chat()->beginTurn($conversation, 'Help me');
        $this->chat()->complete($turn['assistant']);

        Http::assertSent(function ($request) {
            $first = $request->data()['messages'][0];

            return $first['role'] === 'system'
                && $first['content'] === 'You are a concise support agent.';
        });
    }

    public function test_only_one_persona_can_be_the_default(): void
    {
        $first = Persona::create(['name' => 'A', 'system_prompt' => 'a', 'is_default' => true, 'status' => 'active']);
        $second = Persona::create(['name' => 'B', 'system_prompt' => 'b', 'is_default' => true, 'status' => 'active']);

        // Two defaults would make which prompt a new chat gets depend on row
        // order.
        $this->assertFalse($first->fresh()->is_default);
        $this->assertTrue($second->fresh()->is_default);
        $this->assertSame('B', Persona::default()->name);
    }
}
