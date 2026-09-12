<?php

namespace App\Domains\Knowledge\Services;

use App\Domains\AI\Exceptions\ProviderFailed;
use App\Domains\Chat\Models\Conversation;
use App\Domains\Chat\Models\Message;
use App\Domains\Knowledge\Contracts\VectorStore;
use App\Domains\Knowledge\Models\KnowledgeBase;
use App\Domains\Knowledge\Models\RetrievalLog;
use App\Domains\Knowledge\Stores\DatabaseVectorStore;
use App\Domains\Knowledge\Support\ScoredChunk;
use App\Models\User;
use RuntimeException;
use Throwable;

/**
 * Finding the passages that answer a question (§17).
 *
 * THE PERMISSION DECISION HAPPENS HERE AND ONLY HERE. The bases a search may
 * touch are resolved from the ASKING USER, never from what the conversation
 * claims to be attached to — a conversation row can be edited, a grant cannot
 * be forged. `VectorStore::search()` then receives an explicit list of base
 * ids and treats an empty one as "search nothing", so there is no path where a
 * missing filter becomes an unrestricted search.
 *
 * RETRIEVAL NEVER BREAKS A CONVERSATION. If the embedding provider is down or
 * no embedding model is configured, the customer gets an ordinary answer
 * without their documents rather than an error — the failure is recorded for
 * the owner, who is the person who can fix it. A chat that refuses to run
 * because a knowledge base is unavailable is worse than a chat that answers
 * from what it knows.
 */
class RetrievalService
{
    public function __construct(
        private readonly EmbeddingService $embeddings,
        private readonly VectorStore $store,
    ) {}

    /**
     * The passages to put in front of the model for this question.
     *
     * @return array<int, ScoredChunk>
     */
    public function retrieve(Conversation $conversation, string $question, ?Message $message = null): array
    {
        $user = $conversation->user;

        if (! $user || trim($question) === '') {
            return [];
        }

        $bases = $this->basesFor($conversation, $user);

        if ($bases === []) {
            return [];
        }

        $startedAt = hrtime(true);

        try {
            $chunks = $this->search($bases, $question, $user);
        } catch (ProviderFailed|RuntimeException|Throwable $e) {
            // Recorded against the owner's diagnostics, never shown to the
            // customer as a failure. They asked a question; they get an answer.
            $this->log($message, $user, null, 0, [], 0, $startedAt);

            return [];
        }

        $primary = $chunks === [] ? null : $chunks[0]->knowledgeBaseId;

        $this->log(
            $message,
            $user,
            $primary,
            count($chunks),
            $chunks,
            $this->store instanceof DatabaseVectorStore
                ? $this->store->scanSize(array_map(fn (KnowledgeBase $b) => $b->getKey(), $bases))
                : 0,
            $startedAt,
        );

        return $chunks;
    }

    /**
     * The bases this conversation is allowed to draw on.
     *
     * Attached AND readable. The intersection matters: a customer can attach a
     * base and then have the grant withdrawn, and the next question must not
     * still search it.
     *
     * @return array<int, KnowledgeBase>
     */
    public function basesFor(Conversation $conversation, User $user): array
    {
        $attached = $conversation->knowledgeBases()->get();

        return $attached
            ->filter(fn (KnowledgeBase $base) => $base->isReadableBy($user))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, KnowledgeBase>  $bases
     * @return array<int, ScoredChunk>
     */
    private function search(array $bases, string $question, User $user): array
    {
        // Settings come from the bases being searched. Where several are
        // attached the STRICTEST wins: the highest minimum score and the
        // smallest top-k, because an owner who tightened one base's retrieval
        // did so for a reason and attaching a loose base beside it must not
        // quietly undo that.
        $topK = min(array_map(fn (KnowledgeBase $b) => (int) $b->top_k, $bases));
        $minScore = max(array_map(fn (KnowledgeBase $b) => (float) $b->min_score, $bases));

        // One model for the query, and it must be the one the documents were
        // embedded with — vectors from two models are not comparable, and
        // comparing them produces confident nonsense rather than an error.
        $model = $this->embeddings->model($bases[0]->embeddingModel);

        $vector = $this->embeddings->embedQuery($question, $model, $user);

        return $this->store->search(
            $vector,
            array_map(fn (KnowledgeBase $b) => (int) $b->getKey(), $bases),
            max(1, $topK),
            $minScore,
        );
    }

    /** @param array<int, ScoredChunk> $chunks */
    private function log(
        ?Message $message,
        User $user,
        ?int $knowledgeBaseId,
        int $returned,
        array $chunks,
        int $candidates,
        float $startedAt,
    ): void {
        RetrievalLog::create([
            'message_id' => $message?->getKey(),
            'knowledge_base_id' => $knowledgeBaseId,
            'user_id' => $user->getKey(),
            'candidates' => $candidates,
            'returned' => $returned,
            'top_score' => $chunks === [] ? null : round($chunks[0]->score, 5),
            'chunk_ids' => array_map(fn (ScoredChunk $c) => $c->chunkId, $chunks),
            'scores' => array_map(fn (ScoredChunk $c) => round($c->score, 5), $chunks),
            'took_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
            'retrieved_at' => now(),
        ]);
    }
}
