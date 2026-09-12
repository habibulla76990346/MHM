<?php

namespace App\Domains\Knowledge\Support;

/** One passage of a document, with where it came from. */
final class Chunk
{
    public function __construct(
        public readonly int $ordinal,
        public readonly string $content,
        public readonly ?string $locator = null,
    ) {}

    public function tokenEstimate(): int
    {
        // The same four-characters-per-token approximation the chat context
        // budget uses. Being consistent matters more than being exact: two
        // different estimates in one system produce a context that overflows
        // on the boundary nobody tested.
        return (int) ceil(mb_strlen($this->content) / 4);
    }

    public function checksum(): string
    {
        return hash('sha256', $this->content);
    }
}
