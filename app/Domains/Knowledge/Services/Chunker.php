<?php

namespace App\Domains\Knowledge\Services;

use App\Domains\Knowledge\Support\Chunk;

/**
 * Cutting a document into passages worth searching (§17).
 *
 * THE WHOLE DIFFICULTY IS WHERE TO CUT. Split on a fixed character count and
 * you cut sentences in half, so a passage that answers a question is stored as
 * two passages that answer nothing. Split only on paragraphs and one long
 * section becomes a chunk too big to be a useful search result.
 *
 * So it splits on the strongest boundary available and only falls back to a
 * weaker one when it must: paragraph, then sentence, then whitespace, then —
 * only for text with no spaces at all, which is real in some scripts — the
 * character limit.
 *
 * OVERLAP EXISTS FOR ONE REASON: a fact that straddles a boundary. Without it,
 * "the grace period is" ends one chunk and "seven days" starts the next, and
 * neither retrieves for "how long is the grace period". The cost is storing
 * some text twice, which is cheap; the cost of not doing it is an answer of
 * "I don't know" to a question the document answers.
 */
class Chunker
{
    /** Never produce a chunk smaller than this except as the last one. */
    private const MINIMUM = 120;

    /**
     * @param  array<int, array{locator: string, offset: int}>  $markers
     * @return array<int, Chunk>
     */
    public function chunk(string $text, int $size = 1200, int $overlap = 180, array $markers = []): array
    {
        $text = trim($text);

        if ($text === '') {
            return [];
        }

        $size = max(self::MINIMUM * 2, $size);
        // Overlap must be a fraction of the chunk, or the window advances by
        // almost nothing and a long document produces chunks for ever.
        $overlap = max(0, min($overlap, (int) floor($size / 2)));

        $chunks = [];
        $length = mb_strlen($text);
        $start = 0;
        $ordinal = 0;

        while ($start < $length) {
            $remaining = $length - $start;

            if ($remaining <= $size) {
                $piece = mb_substr($text, $start);
                $chunks[] = new Chunk($ordinal, trim($piece), $this->locatorFor($start, $markers));
                break;
            }

            $window = mb_substr($text, $start, $size);
            $cut = $this->boundary($window);

            $piece = trim(mb_substr($window, 0, $cut));

            if ($piece !== '') {
                $chunks[] = new Chunk($ordinal, $piece, $this->locatorFor($start, $markers));
                $ordinal++;
            }

            // Advance by the cut, less the overlap — but always forward, or a
            // pathological document loops.
            $advance = max(1, $cut - $overlap);
            $start += $advance;
        }

        return array_values(array_filter(
            $chunks,
            fn (Chunk $chunk) => trim($chunk->content) !== '',
        ));
    }

    /**
     * The best place to cut this window, as an offset into it.
     *
     * Searched from the END backwards, because a boundary near the start would
     * throw away most of the window and produce hundreds of tiny chunks.
     */
    private function boundary(string $window): int
    {
        $length = mb_strlen($window);
        $floor = (int) max(self::MINIMUM, $length * 0.5);

        // A paragraph break is the strongest signal a document gives about
        // where one idea ends.
        $paragraph = mb_strrpos($window, "\n\n");

        if ($paragraph !== false && $paragraph >= $floor) {
            return $paragraph;
        }

        // Then the end of a sentence. Matched with the following space so a
        // decimal point or an abbreviation is not mistaken for one.
        foreach (['. ', '? ', '! ', ".\n", "?\n", "!\n"] as $terminator) {
            $position = mb_strrpos($window, $terminator);

            if ($position !== false && $position >= $floor) {
                return $position + mb_strlen($terminator);
            }
        }

        $line = mb_strrpos($window, "\n");

        if ($line !== false && $line >= $floor) {
            return $line;
        }

        $space = mb_strrpos($window, ' ');

        if ($space !== false && $space >= $floor) {
            return $space;
        }

        // No boundary at all. Scripts without spaces are the honest case here,
        // and cutting mid-word beats never cutting.
        return $length;
    }

    /**
     * Which page or row this chunk starts on.
     *
     * The last marker at or before the chunk's start, which is how a page
     * number works: everything after "page 4" is on page 4 until "page 5".
     *
     * @param  array<int, array{locator: string, offset: int}>  $markers
     */
    private function locatorFor(int $offset, array $markers): ?string
    {
        $locator = null;

        foreach ($markers as $marker) {
            if ($marker['offset'] > $offset) {
                break;
            }

            $locator = $marker['locator'];
        }

        return $locator;
    }
}
