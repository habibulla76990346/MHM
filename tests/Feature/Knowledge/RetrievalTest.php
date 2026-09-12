<?php

namespace Tests\Feature\Knowledge;

use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Models\Subscription;
use App\Domains\Chat\Models\Conversation;
use App\Domains\Knowledge\Contracts\VectorStore;
use App\Domains\Knowledge\Models\KnowledgeBase;
use App\Domains\Knowledge\Models\KnowledgeBaseGrant;
use App\Domains\Knowledge\Models\RetrievalLog;
use App\Domains\Knowledge\Services\IndexingService;
use App\Domains\Knowledge\Services\RetrievalService;
use App\Domains\Knowledge\Support\Vector;
use App\Domains\Security\Services\PermissionRegistry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeEmbedder;
use Tests\Support\IndexesDocuments;
use Tests\TestCase;

/**
 * The Phase 8 gate items: **RAG retrieval returns relevant chunks** and
 * **knowledge base permissions are enforced**.
 *
 * Relevance is asserted against embeddings that genuinely encode word overlap
 * (see `FakeEmbedder`), so "the right passage came first" is arithmetic rather
 * than the order the fixtures happened to be written in.
 */
class RetrievalTest extends TestCase
{
    use IndexesDocuments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpKnowledgeFixtures();
        $this->installEmbeddingStub();
    }

    /** Three passages about three different things, indexed for real. */
    private function library(KnowledgeBase $base): void
    {
        $text = implode("\n\n", [
            'The renewal grace period lasts seven days after the billing period ends. During the grace period the subscription keeps working normally.',
            'Refunds are issued as credit notes. A refund claws back unspent credits from the remaining balance and never takes it below zero.',
            'The office in Bengaluru is open on weekdays between nine in the morning and six in the evening, and closed on public holidays.',
        ]);

        // Chunk size chosen so each paragraph becomes its own passage.
        $base->forceFill(['chunk_size' => 200, 'chunk_overlap' => 0])->save();

        app(IndexingService::class)->ingest(
            $base->fresh(),
            $this->upload('handbook.txt', 'txt', $text),
            $this->user,
        );
    }

    private function conversation(User $user, KnowledgeBase ...$bases): Conversation
    {
        $conversation = Conversation::create([
            'user_id' => $user->getKey(),
            'title' => 'Questions',
        ]);

        foreach ($bases as $base) {
            $conversation->knowledgeBases()->attach($base->getKey());
        }

        return $conversation->fresh();
    }

    private function retrieve(Conversation $conversation, string $question): array
    {
        return app(RetrievalService::class)->retrieve($conversation, $question);
    }

    // -- relevance -----------------------------------------------------------

    public function test_a_question_retrieves_the_passage_that_answers_it(): void
    {
        $base = $this->base(['top_k' => 1, 'min_score' => 0.05]);
        $this->library($base);

        $chunks = $this->retrieve($this->conversation($this->user, $base), 'how long is the renewal grace period');

        $this->assertCount(1, $chunks);
        $this->assertStringContainsString('grace period lasts seven days', $chunks[0]->content);
    }

    public function test_a_different_question_retrieves_a_different_passage(): void
    {
        // The same base, so nothing about the fixture ordering can explain it.
        $base = $this->base(['top_k' => 1, 'min_score' => 0.05]);
        $this->library($base);
        $conversation = $this->conversation($this->user, $base);

        $refunds = $this->retrieve($conversation, 'are refunds issued as credit notes');
        $office = $this->retrieve($conversation, 'what time does the Bengaluru office open on weekdays');

        $this->assertStringContainsString('Refunds are issued as credit notes', $refunds[0]->content);
        $this->assertStringContainsString('Bengaluru', $office[0]->content);
    }

    public function test_a_question_the_documents_do_not_answer_returns_nothing(): void
    {
        // The floor is what stops a document about office hours answering a
        // question about astrophysics with confident nonsense.
        $base = $this->base(['top_k' => 3, 'min_score' => 0.35]);
        $this->library($base);

        $this->assertSame([], $this->retrieve(
            $this->conversation($this->user, $base),
            'photosynthesis chlorophyll wavelength absorption spectra',
        ));
    }

    public function test_results_come_back_best_first(): void
    {
        $base = $this->base(['top_k' => 3, 'min_score' => 0.0]);
        $this->library($base);

        $chunks = $this->retrieve($this->conversation($this->user, $base), 'grace period after the billing period ends');

        $scores = array_map(fn ($c) => $c->score, $chunks);
        $sorted = $scores;
        rsort($sorted);

        $this->assertSame($sorted, $scores);
        $this->assertStringContainsString('grace period', $chunks[0]->content);
    }

    public function test_a_passage_carries_a_citation_a_person_can_check(): void
    {
        $base = $this->base(['top_k' => 1, 'min_score' => 0.05]);
        $this->library($base);

        $chunk = $this->retrieve($this->conversation($this->user, $base), 'grace period')[0];

        $this->assertStringContainsString('handbook.txt', $chunk->citation());
    }

    // -- permissions ---------------------------------------------------------

    public function test_another_customers_personal_base_is_never_searched(): void
    {
        $base = $this->base();
        $this->library($base);

        $stranger = User::factory()->create();
        $stranger->assignRole(PermissionRegistry::CUSTOMER);
        $stranger = $stranger->fresh();

        // The stranger attaches somebody else's base to their own
        // conversation — the row exists, so only the permission check stands
        // between them and another person's documents.
        $conversation = $this->conversation($stranger, $base);

        $this->assertFalse($base->isReadableBy($stranger));
        $this->assertSame([], $this->retrieve($conversation, 'grace period'));
    }

    public function test_a_shared_base_needs_an_explicit_grant(): void
    {
        $shared = KnowledgeBase::create([
            'name' => 'Company handbook',
            'scope' => KnowledgeBase::SCOPE_SHARED,
            'embedding_model_id' => $this->embedModel->getKey(),
            'chunk_size' => 200, 'chunk_overlap' => 0, 'min_score' => 0.05, 'top_k' => 1,
        ]);
        $this->library($shared);

        $conversation = $this->conversation($this->user, $shared);

        // No grant: nothing, even though the base is active and attached.
        $this->assertSame([], $this->retrieve($conversation, 'grace period'));

        KnowledgeBaseGrant::create([
            'knowledge_base_id' => $shared->getKey(),
            'user_id' => $this->user->getKey(),
        ]);

        $this->assertCount(1, $this->retrieve($conversation->fresh(), 'grace period'));
    }

    public function test_a_grant_to_a_plan_reaches_the_customers_on_it(): void
    {
        $plan = Plan::create([
            'name' => 'Pro', 'slug' => 'pro-kb', 'status' => Plan::STATUS_ACTIVE,
            'billing_cycle' => 'monthly', 'credits_per_period' => 100,
        ]);

        $shared = KnowledgeBase::create([
            'name' => 'Pro handbook',
            'scope' => KnowledgeBase::SCOPE_SHARED,
            'embedding_model_id' => $this->embedModel->getKey(),
            'chunk_size' => 200, 'chunk_overlap' => 0, 'min_score' => 0.05, 'top_k' => 1,
        ]);
        $this->library($shared);

        KnowledgeBaseGrant::create(['knowledge_base_id' => $shared->getKey(), 'plan_id' => $plan->getKey()]);

        $conversation = $this->conversation($this->user, $shared);

        // Not on the plan yet.
        $this->assertSame([], $this->retrieve($conversation, 'grace period'));

        Subscription::create([
            'user_id' => $this->user->getKey(),
            'plan_id' => $plan->getKey(),
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_start' => now()->subDay(),
            'current_period_end' => now()->addMonth(),
        ]);

        $this->assertCount(1, $this->retrieve($conversation->fresh(), 'grace period'));
    }

    public function test_withdrawing_a_grant_stops_the_next_question(): void
    {
        $shared = KnowledgeBase::create([
            'name' => 'Handbook',
            'scope' => KnowledgeBase::SCOPE_SHARED,
            'embedding_model_id' => $this->embedModel->getKey(),
            'chunk_size' => 200, 'chunk_overlap' => 0, 'min_score' => 0.05, 'top_k' => 1,
        ]);
        $this->library($shared);

        $grant = KnowledgeBaseGrant::create([
            'knowledge_base_id' => $shared->getKey(),
            'user_id' => $this->user->getKey(),
        ]);

        $conversation = $this->conversation($this->user, $shared);
        $this->assertCount(1, $this->retrieve($conversation, 'grace period'));

        // Attachment is not permission: the conversation still names the base.
        $grant->delete();

        $this->assertSame([], $this->retrieve($conversation->fresh(), 'grace period'));
    }

    public function test_switching_a_base_off_stops_it_being_searched(): void
    {
        $base = $this->base();
        $this->library($base);
        $conversation = $this->conversation($this->user, $base);

        $this->assertNotSame([], $this->retrieve($conversation, 'grace period'));

        $base->forceFill(['is_active' => false])->save();

        $this->assertSame([], $this->retrieve($conversation->fresh(), 'grace period'));
    }

    public function test_the_store_treats_an_empty_base_list_as_nothing_not_everything(): void
    {
        $base = $this->base();
        $this->library($base);

        // The list IS the permission decision. A store that read an empty list
        // as "no filter" would hand a customer every document on the platform.
        $this->assertSame([], app(VectorStore::class)->search(
            FakeEmbedder::vector('grace period'), [], 5, 0.0,
        ));
    }

    public function test_vectors_from_a_different_model_are_never_compared(): void
    {
        $base = $this->base();
        $this->library($base);

        // A base re-embedded with a model of another size. Comparing the
        // overlapping dimensions would produce a plausible number for a
        // meaningless comparison.
        $this->assertSame(0.0, Vector::cosine([1.0, 0.0, 0.0], [1.0, 0.0]));

        $short = array_fill(0, 8, 0.1);

        $this->assertSame([], app(VectorStore::class)->search(
            $short, [$base->getKey()], 5, -1.0,
        ));
    }

    // -- the record ----------------------------------------------------------

    public function test_every_retrieval_is_logged_so_an_answer_can_be_explained(): void
    {
        $base = $this->base(['top_k' => 2, 'min_score' => 0.05]);
        $this->library($base);

        $this->retrieve($this->conversation($this->user, $base), 'grace period');

        $log = RetrievalLog::latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame($this->user->getKey(), $log->user_id);
        $this->assertGreaterThan(0, $log->returned);
        $this->assertNotSame([], $log->chunk_ids);
        $this->assertGreaterThan(0.0, $log->top_score);

        // Ids and scores, never the text: duplicating it would double the
        // storage of the largest table in the system.
        $this->assertArrayNotHasKey('content', $log->getAttributes());
    }

    public function test_a_failing_embedding_provider_does_not_break_the_conversation(): void
    {
        $base = $this->base();
        $this->library($base);
        $conversation = $this->conversation($this->user, $base);

        // The provider dies after indexing succeeded. Flipped as STATE rather
        // than re-faked: a second Http::fake() for the same pattern does not
        // replace the first, so re-faking here would keep serving the healthy
        // response and this test would pass without testing anything.
        $this->providerIsDown = true;

        // The customer asked a question. They get an ordinary answer without
        // their documents — not an error page.
        $this->assertSame([], $this->retrieve($conversation, 'grace period'));

        // And the owner, who can fix it, has the record.
        $this->assertSame(0, (int) RetrievalLog::latest('id')->first()->returned);
    }
}
