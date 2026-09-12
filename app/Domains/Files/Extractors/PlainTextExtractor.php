<?php

namespace App\Domains\Files\Extractors;

use App\Domains\Files\Contracts\TextExtractor;
use App\Domains\Files\Extraction\ExtractedText;
use App\Domains\Files\Models\File;

/**
 * Text that is already text.
 *
 * The only real work is ENCODING. A file typed on a Windows machine in India
 * is as likely to be Windows-1252 as UTF-8, and handing invalid UTF-8 to a
 * provider produces either a rejected request or mojibake in the answer.
 */
class PlainTextExtractor implements TextExtractor
{
    public const KEY = 'plain-text';

    public function key(): string
    {
        return self::KEY;
    }

    public function extensions(): array
    {
        return ['txt', 'md', 'markdown', 'log', 'json', 'xml', 'html', 'htm'];
    }

    public function handles(File $file): bool
    {
        return in_array(strtolower((string) $file->extension), $this->extensions(), true);
    }

    public function extract(File $file, string $path): ExtractedText
    {
        $raw = @file_get_contents($path);

        if ($raw === false) {
            return ExtractedText::unusable(self::KEY, __('This file could not be read.'));
        }

        $text = $this->toUtf8($raw);

        // Markup is text with furniture. Stripping tags is right for a
        // knowledge base — nobody asks a question about a <div>.
        if (in_array(strtolower((string) $file->extension), ['html', 'htm', 'xml'], true)) {
            $text = $this->stripMarkup($text);
        }

        return ExtractedText::of($text, self::KEY);
    }

    private function toUtf8(string $raw): string
    {
        // A byte-order mark is invisible in an editor and a stray character to
        // everything else.
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;

        if (mb_check_encoding($raw, 'UTF-8')) {
            return $raw;
        }

        // The two that actually turn up. `mb_convert_encoding` picks the first
        // that fits rather than guessing wildly.
        return mb_convert_encoding($raw, 'UTF-8', ['UTF-8', 'Windows-1252', 'ISO-8859-1']);
    }

    private function stripMarkup(string $text): string
    {
        // Script and style hold code, not content, and their contents survive
        // strip_tags as a wall of CSS.
        $text = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $text);
        $text = (string) preg_replace('#<(br|/p|/div|/li|/h[1-6])\s*/?>#i', "\n", $text);

        return html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
