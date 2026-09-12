<?php

namespace App\Domains\Knowledge\Support;

/**
 * Vectors, and what produced them.
 *
 * The model and dimensions travel WITH the vectors rather than being looked up
 * later, because they are what makes a vector comparable to another one. A
 * chunk stored without them is a chunk nobody can safely search against after
 * the owner changes their embedding model.
 */
final class EmbeddingBatch
{
    /** @param array<int, array<int, float>> $vectors */
    public function __construct(
        public readonly array $vectors,
        public readonly string $model,
        public readonly int $dimensions,
        public readonly int $latencyMs = 0,
    ) {}

    public function count(): int
    {
        return count($this->vectors);
    }
}
