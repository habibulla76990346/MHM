<?php

namespace App\Domains\Files\Extraction;

/**
 * What came out of a file, and whether it is worth anything.
 *
 * `usable` is separate from "no exception was thrown" on purpose. A scanned
 * PDF parses perfectly and yields nothing; a spreadsheet of numbers yields
 * text that answers no question. Both need to reach the customer as a plain
 * sentence rather than as a document stuck at 0 chunks with no explanation.
 */
final class ExtractedText
{
    private function __construct(
        public readonly string $text,
        public readonly string $extractorKey,
        public readonly bool $usable,
        public readonly ?string $reason = null,
        /** @var array<int, array{locator: string, offset: int}> page or row markers, for citations */
        public readonly array $markers = [],
    ) {}

    /** @param array<int, array{locator: string, offset: int}> $markers */
    public static function of(string $text, string $extractorKey, array $markers = []): self
    {
        $text = self::tidy($text);

        // A handful of characters is a filename, a header, or the word "Scan".
        // Treating it as a document produces one useless chunk that then
        // competes with real evidence in every search.
        if (mb_strlen($text) < self::MINIMUM) {
            return self::unusable(
                $extractorKey,
                __('No readable text was found in this file. If it is a scan or a photo of a document, it needs to be converted to text first.'),
            );
        }

        return new self($text, $extractorKey, true, null, $markers);
    }

    public static function unusable(string $extractorKey, string $reason): self
    {
        return new self('', $extractorKey, false, $reason);
    }

    public function length(): int
    {
        return mb_strlen($this->text);
    }

    /**
     * Below this, a file has no content worth indexing.
     *
     * Not a guess: it is roughly one sentence. Anything shorter cannot answer
     * a question, and admitting that is more useful than indexing it.
     */
    private const MINIMUM = 40;

    /**
     * Normalise whitespace without destroying structure.
     *
     * Paragraph breaks are kept because they are what the chunker splits on;
     * runs of spaces, tabs and stray carriage returns are not, because PDF
     * extraction produces them by the thousand and they cost tokens.
     */
    private static function tidy(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        // Control characters that survive extraction and mean nothing.
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $text);
        $text = (string) preg_replace('/[ \t]+/u', ' ', $text);
        $text = (string) preg_replace('/ *\n */u', "\n", $text);
        $text = (string) preg_replace('/\n{3,}/u', "\n\n", $text);

        return trim($text);
    }
}
