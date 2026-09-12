<?php

namespace App\Domains\Knowledge\Stores;

use App\Domains\Knowledge\Contracts\VectorStore;
use App\Domains\Knowledge\Models\DocumentChunk;
use App\Domains\Knowledge\Models\KnowledgeBase;
use App\Domains\Knowledge\Support\ScoredChunk;
use App\Domains\Knowledge\Support\Vector;

/**
 * Vectors in MySQL, similarity in PHP (decision D-03).
 *
 * HONEST ABOUT WHAT THIS IS. MySQL cannot index a vector, so this reads every
 * chunk in the bases being searched and scores each one. That is a linear
 * scan, and it is the right trade for the size D-03 anticipates: a personal
 * knowledge base is hundreds to a few thousand chunks, where a scan is
 * milliseconds and a dedicated vector database is an extra service to install,
 * back up and pay for.
 *
 * IT IS ALSO WHY THE CONTRACT EXISTS. When a base outgrows this — and the
 * `SCAN_CEILING` below is where that starts — the answer is a pgvector or
 * Qdrant implementation of `VectorStore`, not a rewrite of retrieval.
 *
 * The scan reads in chunks of rows rather than all at once, because a
 * knowledge base of 50,000 vectors is 300 MB of float data and loading it into
 * one array is how a queue worker dies on shared hosting.
 */
class DatabaseVectorStore implements VectorStore
{
    public const KEY = 'database';

    /**
     * Rows held in memory at once.
     *
     * At 1536 dimensions each row is about 6 KB of vector plus its text, so a
     * page of 500 is a few megabytes — comfortable inside a 256 MB limit while
     * still amortising the query.
     */
    private const PAGE = 500;

    /**
     * Beyond this many chunks in one search, the linear scan stops being
     * milliseconds. It is not a hard stop — cutting a customer's search off
     * silently would be worse — but it is the number that says "time for a
     * real vector store", and diagnostics reports it.
     */
    public const SCAN_CEILING = 50000;

    public function key(): string
    {
        return self::KEY;
    }

    /**
     * @param  array<int, float>  $query
     * @param  array<int, int>  $knowledgeBaseIds
     * @return array<int, ScoredChunk>
     */
    public function search(array $query, array $knowledgeBaseIds, int $topK, float $minScore): array
    {
        // AN EMPTY LIST SEARCHES NOTHING. Not "everything" — the list is the
        // permission decision, already made by the caller, and treating an
        // empty one as unrestricted would hand a customer every document on
        // the platform.
        if ($query === [] || $knowledgeBaseIds === [] || $topK < 1) {
            return [];
        }

        $dimensions = count($query);
        $best = [];

        DocumentChunk::query()
            ->whereIn('knowledge_base_id', $knowledgeBaseIds)
            ->whereNotNull('embedding')
            // Vectors from a different model are not comparable with this
            // query at all, so they are excluded in SQL rather than scored to
            // zero — that keeps them out of the scan entirely.
            ->where('dimensions', $dimensions)
            ->select(['id', 'document_id', 'knowledge_base_id', 'content', 'locator', 'embedding', 'dimensions'])
            ->with(['document:id,title'])
            ->orderBy('id')
            ->chunk(self::PAGE, function ($rows) use ($query, $dimensions, $minScore, $topK, &$best) {
                foreach ($rows as $row) {
                    $score = Vector::cosine($query, Vector::unpack((string) $row->embedding, $dimensions));

                    if ($score < $minScore) {
                        continue;
                    }

                    $best[] = new ScoredChunk(
                        chunkId: (int) $row->id,
                        documentId: (int) $row->document_id,
                        knowledgeBaseId: (int) $row->knowledge_base_id,
                        content: (string) $row->content,
                        score: $score,
                        locator: $row->locator,
                        documentTitle: $row->document?->title,
                    );
                }

                // Trimmed every page rather than at the end, so memory is
                // bounded by topK and not by how much matched.
                if (count($best) > $topK * 4) {
                    $best = $this->top($best, $topK);
                }
            });

        return $this->top($best, $topK);
    }

    public function count(KnowledgeBase $base): int
    {
        return DocumentChunk::where('knowledge_base_id', $base->getKey())
            ->whereNotNull('embedding')
            ->count();
    }

    /** How many chunks a search across these bases would have to scan. */
    public function scanSize(array $knowledgeBaseIds): int
    {
        return $knowledgeBaseIds === [] ? 0 : DocumentChunk::whereIn('knowledge_base_id', $knowledgeBaseIds)
            ->whereNotNull('embedding')
            ->count();
    }

    /**
     * @param  array<int, ScoredChunk>  $chunks
     * @return array<int, ScoredChunk>
     */
    private function top(array $chunks, int $topK): array
    {
        usort($chunks, fn (ScoredChunk $a, ScoredChunk $b) => $b->score <=> $a->score);

        return array_slice($chunks, 0, $topK);
    }
}
