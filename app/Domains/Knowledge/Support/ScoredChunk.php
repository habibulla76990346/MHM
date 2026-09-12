<?php

namespace App\Domains\Knowledge\Support;

/** A retrieved passage and how well it matched. */
final class ScoredChunk
{
    public function __construct(
        public readonly int $chunkId,
        public readonly int $documentId,
        public readonly int $knowledgeBaseId,
        public readonly string $content,
        public readonly float $score,
        public readonly ?string $locator = null,
        public readonly ?string $documentTitle = null,
    ) {}

    /** How this passage is cited to the model, and to the reader. */
    public function citation(): string
    {
        $title = $this->documentTitle ?: __('document');

        return $this->locator ? $title.', '.$this->locator : $title;
    }
}
