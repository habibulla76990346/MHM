<?php

namespace Tests\Feature\Knowledge;

use App\Domains\AI\Models\AiModel;
use App\Domains\AI\Models\AiModelCapability;
use App\Domains\AI\Support\Capability;
use App\Domains\Chat\Models\Conversation;
use App\Domains\Chat\Models\Message;
use App\Domains\Chat\Services\ChatService;
use App\Domains\Chat\Services\ContextBuilder;
use App\Domains\Knowledge\Models\KnowledgeBase;
use App\Domains\Knowledge\Services\IndexingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeEmbedder;
use Tests\Support\IndexesDocuments;
use Tests\TestCase;

/**
 * "Upload a PDF and ask questions about it" — the sentence Phase 8 exists to
 * make true.
 *
 * These assertions are about what actually reaches the provider, captured from
 * the request the adapter sent. A retrieval layer that finds the right passage
 * and then fails to put it in the prompt is a retrieval layer that does
 * nothing.
 */
class ChatWithDocumentsTest extends TestCase
{
    use IndexesDocuments;
    use RefreshDatabase;

    /** @var array<int, array<string, mixed>> every chat payload the fake provider received */
    private array $chatPayloads = [];

    private AiModel $chatModel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpKnowledgeFixtures();

        $this->chatModel = AiModel::create([
            'provider_id' => $this->embedModel->provider_id,
            'model_identifier' => 'chat-small',
            'display_name' => 'Chat Small',
            'is_enabled' => true,
            'context_window' => 32000,
            'max_output_tokens' => 2048,
        ]);

        AiModelCapability::syncForModel($this->chatModel, [Capability::CHAT, Capability::STREAMING]);

        settings()->set('routing.max_retries', 0);

        // ONE stub for both endpoints, recording what chat was actually sent.
        Http::fake(['*' => function ($request) {
            if ($this->providerIsDown) {
                return Http::response(['error' => ['message' => 'down']], 500);
            }

            if (str_contains($request->url(), '/embeddings')) {
                return Http::response(FakeEmbedder::response($request->data()));
            }

            if (str_contains($request->url(), '/chat/completions')) {
                $this->chatPayloads[] = $request->data();

                return Http::response([
                    'model' => 'chat-small',
                    'choices' => [['message' => ['content' => 'Seven days.'], 'finish_reason' => 'stop']],
                    'usage' => ['prompt_tokens' => 40, 'completion_tokens' => 3],
                ]);
            }

            return Http::response(['data' => []]);
        }]);
    }

    private function withHandbook(): KnowledgeBase
    {
        $base = $this->base(['chunk_size' => 200, 'chunk_overlap' => 0, 'min_score' => 0.05, 'top_k' => 2]);

        app(IndexingService::class)->ingest($base, $this->upload('handbook.txt', 'txt', implode("\n\n", [
            'The renewal grace period lasts seven days after the billing period ends.',
            'The office in Bengaluru is closed on public holidays and open on weekdays.',
        ])), $this->user);

        return $base->fresh();
    }

    private function conversationWith(KnowledgeBase ...$bases): Conversation
    {
        $conversation = Conversation::create(['user_id' => $this->user->getKey(), 'title' => 'Docs']);

        foreach ($bases as $base) {
            $conversation->knowledgeBases()->attach($base->getKey());
        }

        return $conversation->fresh();
    }

    /** Every system message the provider was sent on the last chat call. */
    private function systemMessages(): array
    {
        $payload = end($this->chatPayloads) ?: [];

        return array_values(array_filter(
            (array) ($payload['messages'] ?? []),
            fn (array $m) => ($m['role'] ?? '') === 'system',
        ));
    }

    // -- the passage reaches the model ---------------------------------------

    public function test_the_answering_passage_is_put_in_front_of_the_model(): void
    {
        $conversation = $this->conversationWith($this->withHandbook());

        $turn = app(ChatService::class)->beginTurn($conversation, 'How long is the renewal grace period?');
        app(ChatService::class)->complete($turn['assistant']);

        $system = implode("\n", array_column($this->systemMessages(), 'content'));

        $this->assertStringContainsString('grace period lasts seven days', $system);
        // Cited, so the customer can check it and the model does not present
        // their own document back to them as its own knowledge.
        $this->assertStringContainsString('[handbook.txt', $system);
        // And told what to do when the documents do not answer the question.
        $this->assertStringContainsString('say plainly when they do not contain the answer', $system);

        $this->assertSame(Message::STATUS_COMPLETE, $turn['assistant']->fresh()->status);
    }

    public function test_passages_go_as_a_system_message_never_as_the_customer(): void
    {
        $conversation = $this->conversationWith($this->withHandbook());

        $turn = app(ChatService::class)->beginTurn($conversation, 'How long is the renewal grace period?');
        app(ChatService::class)->complete($turn['assistant']);

        $payload = end($this->chatPayloads);

        foreach ((array) $payload['messages'] as $message) {
            if (($message['role'] ?? '') === 'user') {
                // As a user message the model would answer the document
                // instead of the question, and the customer would see text
                // they never typed attributed to them.
                $this->assertStringNotContainsString('handbook.txt', (string) ($message['content'] ?? ''));
            }
        }
    }

    public function test_a_conversation_with_no_documents_sends_none(): void
    {
        $this->withHandbook();

        // The base exists and belongs to this customer — it is simply not
        // attached to this conversation.
        $conversation = $this->conversationWith();

        $turn = app(ChatService::class)->beginTurn($conversation, 'How long is the renewal grace period?');
        app(ChatService::class)->complete($turn['assistant']);

        $this->assertStringNotContainsString('handbook.txt', implode("\n", array_column($this->systemMessages(), 'content')));
    }

    public function test_documents_never_crowd_out_the_conversation(): void
    {
        // Retrieval spends the same budget the conversation does, and the
        // ceiling is whichever is LOWER: the owner's setting, or a third of
        // this model's window. A small model must not have its whole context
        // filled with documents while forgetting what was being discussed.
        settings()->set('chat.context_token_budget', 600);
        settings()->set('knowledge.max_context_tokens', 100000);

        // Two passages long enough to be separate chunks, and long enough that
        // only one of them fits under a third of the budget.
        $grace = 'The renewal grace period lasts seven days after the billing period ends. '
            .str_repeat('During the grace period the subscription continues to work exactly as before. ', 8);
        $office = 'The office in Bengaluru is closed on public holidays. '
            .str_repeat('Weekday opening hours are nine in the morning until six in the evening. ', 8);

        $base = $this->base(['chunk_size' => 800, 'chunk_overlap' => 0, 'min_score' => 0.02, 'top_k' => 2]);

        app(IndexingService::class)->ingest(
            $base,
            $this->upload('handbook.txt', 'txt', $grace."\n\n".$office),
            $this->user,
        );

        $this->assertSame(2, $base->chunks()->count(), 'The fixture must produce two separate passages.');

        $conversation = $this->conversationWith($base->fresh());

        $turn = app(ChatService::class)->beginTurn($conversation, 'How long is the renewal grace period?');
        app(ChatService::class)->complete($turn['assistant']);

        $system = implode("\n", array_column($this->systemMessages(), 'content'));

        // The best passage fits. The second is dropped rather than allowed to
        // eat the conversation's share of the context.
        $this->assertStringContainsString('grace period lasts seven days', $system);
        $this->assertStringNotContainsString('Bengaluru', $system);
    }

    public function test_the_feature_switch_stops_retrieval_entirely(): void
    {
        settings()->set('knowledge.enabled', false);

        $conversation = $this->conversationWith($this->withHandbook());

        $turn = app(ChatService::class)->beginTurn($conversation, 'How long is the renewal grace period?');
        app(ChatService::class)->complete($turn['assistant']);

        $this->assertStringNotContainsString('handbook.txt', implode("\n", array_column($this->systemMessages(), 'content')));
    }

    public function test_retrieval_is_driven_by_the_question_not_the_whole_conversation(): void
    {
        $conversation = $this->conversationWith($this->withHandbook());
        $chat = app(ChatService::class);

        // Small talk first, then the real question. A search over the whole
        // conversation retrieves whatever the small talk resembles.
        $first = $chat->beginTurn($conversation, 'Hello there, hope you are well today.');
        $chat->complete($first['assistant']);

        $second = $chat->beginTurn($conversation->fresh(), 'What are the office hours in Bengaluru?');
        $chat->complete($second['assistant']);

        $this->assertStringContainsString('Bengaluru', implode("\n", array_column($this->systemMessages(), 'content')));
    }

    public function test_the_context_builder_asks_only_for_what_it_can_read(): void
    {
        // The same object the chat path uses, exercised directly so a future
        // refactor of ChatService cannot quietly bypass the permission check.
        $base = $this->withHandbook();
        $conversation = $this->conversationWith($base);

        // A real question in the conversation, or `build()` returns an empty
        // array and the loop below asserts nothing — a test that cannot fail.
        app(ChatService::class)->beginTurn($conversation, 'How long is the renewal grace period?');

        $base->forceFill(['is_active' => false])->save();

        $messages = app(ContextBuilder::class)->build($conversation->fresh(), $this->chatModel);

        $this->assertNotSame([], $messages);

        foreach ($messages as $message) {
            $this->assertStringNotContainsString('handbook.txt', (string) $message->content);
        }
    }
}
