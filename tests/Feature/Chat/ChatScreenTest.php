<?php

namespace Tests\Feature\Chat;

use App\Domains\AI\Adapters\OpenAiAdapter;
use App\Domains\AI\Models\AiModel;
use App\Domains\AI\Models\AiModelCapability;
use App\Domains\AI\Models\AiProvider;
use App\Domains\AI\Models\AiProviderCredential;
use App\Domains\AI\Support\Capability;
use App\Domains\Chat\Models\Conversation;
use App\Domains\Chat\Models\Message;
use App\Domains\Chat\Models\MessageFeedback;
use App\Domains\Chat\Services\ChatService;
use App\Domains\Chat\Services\ConversationService;
use App\Domains\Security\Services\PermissionRegistry;
use App\Livewire\Chat;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The chat screen and its endpoints: history, search, rename, archive, delete,
 * feedback, and who may touch whose conversation.
 */
class ChatScreenTest extends TestCase
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
            'name' => 'Screen Provider',
            'adapter_type' => OpenAiAdapter::KEY,
            'api_base_url' => 'https://api.screen.test/v1',
            'status' => AiProvider::STATUS_ACTIVE,
        ]);

        AiProviderCredential::create([
            'provider_id' => $provider->getKey(),
            'label' => 'Key',
            'credential' => 'sk-screen-KEYKEYKEY6666',
        ]);

        $this->model = AiModel::create([
            'provider_id' => $provider->getKey(),
            'model_identifier' => 'screen-model',
            'display_name' => 'Screen Model',
            'is_enabled' => true,
            'context_window' => 16000,
        ]);

        AiModelCapability::syncForModel($this->model, [Capability::CHAT, Capability::STREAMING]);

        // ONE stub reading mutable state. A second Http::fake() for the same
        // pattern never replaces the first, so per-test re-fakes are silently
        // ignored — the trap recorded in CLAUDE.md.
        Http::fake(['*/chat/completions' => function () {
            if ($this->failWith !== null) {
                return Http::response($this->failWith[0], $this->failWith[1]);
            }

            if ($this->streamBody !== null) {
                return Http::response($this->streamBody);
            }

            return Http::response([
                'choices' => [['message' => ['content' => 'A reply.'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2],
            ]);
        }]);
    }

    /** When set, the provider fails instead: [body, status]. */
    private ?array $failWith = null;

    /** When set, the provider streams this raw SSE body instead. */
    private ?string $streamBody = null;

    private function withMessages(string $text = 'Hello'): Conversation
    {
        $conversation = app(ConversationService::class)->start($this->user);
        $turn = app(ChatService::class)->beginTurn($conversation, $text);
        app(ChatService::class)->complete($turn['assistant']);

        return $conversation->fresh();
    }

    // -- the screen -----------------------------------------------------------

    public function test_the_chat_screen_requires_signing_in(): void
    {
        $this->get('/dashboard')->assertRedirect();
    }

    public function test_the_chat_screen_renders_with_a_composer(): void
    {
        $this->actingAs($this->user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('aziv-composer', false)
            ->assertSee('aziv-chat-thread', false);
    }

    public function test_sending_creates_both_messages_and_starts_a_stream(): void
    {
        Livewire::actingAs($this->user)
            ->test(Chat::class)
            ->set('draft', 'What is two plus two?')
            ->call('send')
            ->assertDispatched('start-stream')
            ->assertSet('draft', '')
            ->assertSet('error', '');

        $this->assertSame(1, Message::where('role', Message::ROLE_USER)->count());
        $this->assertSame(1, Message::where('role', Message::ROLE_ASSISTANT)->count());
    }

    public function test_a_refused_message_is_explained_rather_than_thrown(): void
    {
        settings()->set('chat.max_message_length', 100);

        Livewire::actingAs($this->user)
            ->test(Chat::class)
            ->set('draft', str_repeat('a', 200))
            ->call('send')
            ->assertSet('draft', str_repeat('a', 200))   // not lost
            ->assertNotSet('error', '');

        $this->assertSame(0, Message::count());
    }

    // -- history --------------------------------------------------------------

    public function test_search_covers_message_content_not_just_titles(): void
    {
        $this->withMessages('The mitochondria is the powerhouse');
        $this->withMessages('Something entirely different');

        // Titles come from the first sentence, so people search for things
        // they remember saying halfway through.
        $found = app(ConversationService::class)->list($this->user, false, 'powerhouse');

        $this->assertCount(1, $found);
    }

    public function test_search_treats_wildcards_as_text(): void
    {
        $this->withMessages('Plain conversation');

        // A bare % would otherwise match everything.
        $this->assertCount(0, app(ConversationService::class)->list($this->user, false, '%'));
    }

    public function test_renaming_and_clearing_a_name(): void
    {
        $conversation = $this->withMessages('Original question here');

        Livewire::actingAs($this->user)
            ->test(Chat::class)
            ->call('renameConversation', $conversation->uuid, '  My renamed chat  ');

        $this->assertSame('My renamed chat', $conversation->fresh()->title);

        // Clearing falls back to the first message rather than leaving it blank.
        Livewire::actingAs($this->user)
            ->test(Chat::class)
            ->call('renameConversation', $conversation->uuid, '');

        $this->assertStringContainsString('Original question', $conversation->fresh()->title);
    }

    public function test_archiving_hides_a_conversation_from_the_main_list(): void
    {
        $conversation = $this->withMessages();

        Livewire::actingAs($this->user)
            ->test(Chat::class)
            ->call('toggleArchive', $conversation->uuid);

        $this->assertTrue($conversation->fresh()->is_archived);
        $this->assertCount(0, app(ConversationService::class)->list($this->user));
        $this->assertCount(1, app(ConversationService::class)->list($this->user, archived: true));
    }

    public function test_deleting_is_recoverable_rather_than_immediate(): void
    {
        $conversation = $this->withMessages();

        Livewire::actingAs($this->user)
            ->test(Chat::class)
            ->call('deleteConversation', $conversation->uuid);

        // Gone for the customer; the rows survive until retention removes them,
        // so a mis-click is not permanent.
        $this->assertSoftDeleted('chat_conversations', ['id' => $conversation->getKey()]);
        $this->assertCount(0, app(ConversationService::class)->list($this->user));
    }

    // -- ownership ------------------------------------------------------------

    public function test_a_customer_cannot_touch_another_customers_conversation(): void
    {
        $conversation = $this->withMessages();

        $intruder = User::factory()->create();
        $intruder->assignRole(PermissionRegistry::CUSTOMER);

        // Scoped by owner, so it is not merely forbidden — it is not found.
        // A "forbidden" would confirm the conversation exists.
        try {
            Livewire::actingAs($intruder->fresh())
                ->test(Chat::class)
                ->call('deleteConversation', $conversation->uuid);

            $this->fail('An intruder was able to delete another customer\'s conversation.');
        } catch (ModelNotFoundException) {
            // Expected.
        }

        $this->assertNotSoftDeleted('chat_conversations', ['id' => $conversation->getKey()]);
    }

    public function test_streaming_endpoints_are_owner_only(): void
    {
        $conversation = $this->withMessages();
        $assistant = $conversation->messages()->where('role', Message::ROLE_ASSISTANT)->first();

        $intruder = User::factory()->create();
        $intruder->assignRole(PermissionRegistry::CUSTOMER);

        $this->actingAs($intruder->fresh())
            ->get("/chat/{$assistant->uuid}/stream")
            ->assertForbidden();

        $this->actingAs($intruder->fresh())
            ->post("/chat/{$assistant->uuid}/stop")
            ->assertForbidden();

        $this->actingAs($intruder->fresh())
            ->post("/chat/{$assistant->uuid}/complete")
            ->assertForbidden();
    }

    /**
     * An administrator does NOT get to read customer conversations through the
     * chat screen. Reading someone's chats is a support action with its own
     * permission and audit trail, not a side effect of being an admin.
     */
    public function test_an_administrator_does_not_inherit_access_to_customer_chats(): void
    {
        $conversation = $this->withMessages();

        $admin = User::factory()->create();
        $admin->assignRole(PermissionRegistry::SUPER_ADMIN);

        $assistant = $conversation->messages()->where('role', Message::ROLE_ASSISTANT)->first();

        $this->actingAs($admin->fresh())
            ->get("/chat/{$assistant->uuid}/stream")
            ->assertForbidden();
    }

    // -- endpoints ------------------------------------------------------------

    public function test_the_non_streaming_endpoint_returns_the_answer(): void
    {
        $conversation = app(ConversationService::class)->start($this->user);
        $turn = app(ChatService::class)->beginTurn($conversation, 'Hello');

        $this->actingAs($this->user)
            ->post("/chat/{$turn['assistant']->uuid}/complete")
            ->assertOk()
            ->assertJson(['status' => Message::STATUS_COMPLETE, 'content' => 'A reply.']);
    }

    public function test_a_provider_failure_returns_a_class_and_a_remedy_never_raw_text(): void
    {
        $conversation = app(ConversationService::class)->start($this->user);
        $turn = app(ChatService::class)->beginTurn($conversation, 'Hello');

        $this->failWith = [
            ['error' => ['code' => 'invalid_api_key', 'message' => 'Bad key sk-screen-KEYKEYKEY6666']],
            401,
        ];

        $response = $this->actingAs($this->user)
            ->post("/chat/{$turn['assistant']->uuid}/complete")
            ->assertStatus(502);

        $response->assertJsonPath('error_class', 'authentication');
        $this->assertStringNotContainsString('sk-screen', $response->getContent());
        $this->assertStringNotContainsString('Bad key', $response->getContent());
    }

    public function test_the_stop_endpoint_flags_the_message(): void
    {
        $conversation = app(ConversationService::class)->start($this->user);
        $turn = app(ChatService::class)->beginTurn($conversation, 'Hello');

        $this->actingAs($this->user)
            ->post("/chat/{$turn['assistant']->uuid}/stop")
            ->assertOk()
            ->assertJson(['stopping' => true]);

        $this->assertTrue(app(ChatService::class)->shouldStop($turn['assistant']));
    }

    public function test_the_stream_endpoint_emits_server_sent_events(): void
    {
        $conversation = app(ConversationService::class)->start($this->user);
        $turn = app(ChatService::class)->beginTurn($conversation, 'Hello');

        $this->streamBody = 'data: '.json_encode(['choices' => [['delta' => ['content' => 'Hi']]]])."\n"
            ."data: [DONE]\n";

        $response = $this->actingAs($this->user)->get("/chat/{$turn['assistant']->uuid}/stream");

        $response->assertOk();
        $this->assertStringContainsString('text/event-stream', $response->headers->get('Content-Type'));
        // Nginx buffers proxied responses by default, which defeats SSE.
        $this->assertSame('no', $response->headers->get('X-Accel-Buffering'));

        $content = $response->streamedContent();
        $this->assertStringContainsString('event: delta', $content);
        $this->assertStringContainsString('event: done', $content);
    }

    // -- feedback -------------------------------------------------------------

    public function test_feedback_can_be_given_changed_and_withdrawn(): void
    {
        $conversation = $this->withMessages();
        $assistant = $conversation->messages()->where('role', Message::ROLE_ASSISTANT)->first();

        $component = Livewire::actingAs($this->user)->test(Chat::class);

        $component->call('rate', $assistant->uuid, 1);
        $this->assertSame(1, MessageFeedback::first()->rating);

        $component->call('rate', $assistant->uuid, -1);
        $this->assertSame(-1, MessageFeedback::first()->rating);

        // An opinion you can give but not withdraw is a trap.
        $component->call('rate', $assistant->uuid, -1);
        $this->assertSame(0, MessageFeedback::count());
    }

    // -- model selection ------------------------------------------------------

    public function test_choosing_a_model_pins_it_and_clearing_returns_to_auto(): void
    {
        $conversation = $this->withMessages();

        $component = Livewire::actingAs($this->user)
            ->test(Chat::class, ['conversationUuid' => $conversation->uuid])
            ->set('selectedModelId', $this->model->getKey());

        $this->assertSame(Conversation::ROUTING_SPECIFIC_MODEL, $conversation->fresh()->routing_mode);
        $this->assertSame($this->model->getKey(), $conversation->fresh()->pinned_model_id);

        $component->set('selectedModelId', null);

        $this->assertSame(Conversation::ROUTING_AUTO, $conversation->fresh()->routing_mode);
        $this->assertNull($conversation->fresh()->pinned_model_id);
    }

    public function test_a_pinned_model_that_becomes_unavailable_says_so_specifically(): void
    {
        $conversation = app(ConversationService::class)->start($this->user, null, $this->model->getKey());

        $this->model->update(['is_enabled' => false]);

        Livewire::actingAs($this->user)
            ->test(Chat::class, ['conversationUuid' => $conversation->uuid])
            ->set('draft', 'Hello')
            ->call('send');

        // Not "no model available": the remedy is different.
        $this->assertSame(
            'The model this conversation is pinned to is not available. Choose another model.',
            Livewire::actingAs($this->user)
                ->test(Chat::class, ['conversationUuid' => $conversation->uuid])
                ->set('draft', 'Hello')
                ->call('send')
                ->get('error'),
        );
    }
}
