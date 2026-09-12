<?php

namespace App\Domains\Files\Extractors;

use App\Domains\Files\Contracts\TextExtractor;
use App\Domains\Files\Extraction\ExtractedText;
use App\Domains\Files\Models\File;

/**
 * A spreadsheet export, turned into sentences.
 *
 * A CSV pasted in raw is nearly useless to a model: by the fortieth row it has
 * lost which column is which, and a question about "the price of the second
 * item" has no anchor. So each row is rendered as its own labelled record —
 * `Name: Widget · Price: 400` — which costs more tokens per row and is the
 * only form that survives being chunked.
 */
class CsvExtractor implements TextExtractor
{
    public const KEY = 'csv';

    /** Beyond this a CSV is a database export, not a document. */
    private const MAX_ROWS = 5000;

    public function key(): string
    {
        return self::KEY;
    }

    public function extensions(): array
    {
        return ['csv', 'tsv'];
    }

    public function handles(File $file): bool
    {
        return in_array(strtolower((string) $file->extension), $this->extensions(), true);
    }

    public function extract(File $file, string $path): ExtractedText
    {
        $handle = @fopen($path, 'r');

        if ($handle === false) {
            return ExtractedText::unusable(self::KEY, __('This file could not be read.'));
        }

        $separator = strtolower((string) $file->extension) === 'tsv' ? "\t" : $this->sniff($path);

        $header = null;
        $lines = [];
        $markers = [];
        $rowNumber = 0;
        $offset = 0;

        while (($row = fgetcsv($handle, 0, $separator, '"', '\\')) !== false) {
            if ($row === [null] || $row === []) {
                continue;
            }

            $row = array_map(fn ($v) => trim((string) $v), $row);

            if ($header === null) {
                $header = $row;

                continue;
            }

            $rowNumber++;

            if ($rowNumber > self::MAX_ROWS) {
                $lines[] = __('… the rest of this file was not indexed: it has more than :max rows.', ['max' => self::MAX_ROWS]);
                break;
            }

            $rendered = $this->render($header, $row);

            if ($rendered === '') {
                continue;
            }

            // A citation a person can check: "row 41" is findable in Excel.
            $markers[] = ['locator' => __('row :n', ['n' => $rowNumber]), 'offset' => $offset];
            $offset += mb_strlen($rendered) + 1;
            $lines[] = $rendered;
        }

        fclose($handle);

        if ($header === null) {
            return ExtractedText::unusable(self::KEY, __('This file is empty.'));
        }

        return ExtractedText::of(implode("\n", $lines), self::KEY, $markers);
    }

    /** @param array<int, string> $header @param array<int, string> $row */
    private function render(array $header, array $row): string
    {
        $parts = [];

        foreach ($row as $index => $value) {
            if ($value === '') {
                continue;
            }

            $label = trim((string) ($header[$index] ?? ''));
            $parts[] = $label === '' ? $value : $label.': '.$value;
        }

        return implode(' · ', $parts);
    }

    /** Comma or semicolon — the two that matter, decided from the header row. */
    private function sniff(string $path): string
    {
        $first = (string) @fgets(@fopen($path, 'r') ?: null, 4096);

        return substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
    }
}
