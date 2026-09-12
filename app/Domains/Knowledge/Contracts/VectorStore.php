<?php

namespace App\Domains\Knowledge\Contracts;

use App\Domains\Knowledge\Models\KnowledgeBase;
use App\Domains\Knowledge\Support\ScoredChunk;

/**
 * Where vectors live and how they are searched (decision D-03).
 *
 * D-03 says: start with MySQL, and make the backend swappable without a
 * rewrite. This interface IS that promise. `DatabaseVectorStore` implements it
 * against the `document_chunks` table; a pgvector or Qdrant implementation is
 * a second class registered in its place, and nothing above changes.
 *
 * The interface is deliberately narrow — store, search, forget. Anything
 * richer would leak one backend's capabilities into the contract and make the
 * swap it exists to protect impossible.
 */
interface VectorStore
{
    /** A key for diagnostics and the admin screen. */
    public function key(): string;

    /**
     * Nearest chunks to a query vector, best first.
     *
     * @param  array<int, float>  $query
     * @param  array<int, int>  $knowledgeBaseIds  the ONLY bases that may be searched
     * @return array<int, ScoredChunk>
     */
    public function search(array $query, array $knowledgeBaseIds, int $topK, float $minScore): array;

    /** How many searchable chunks a base holds. */
    public function count(KnowledgeBase $base): int;
}
