<?php

namespace App\Domains\Files\Services;

use App\Domains\Files\Contracts\TextExtractor;
use App\Domains\Files\Extractors\CsvExtractor;
use App\Domains\Files\Extractors\DocxExtractor;
use App\Domains\Files\Extractors\PdfExtractor;
use App\Domains\Files\Extractors\PlainTextExtractor;
use App\Domains\Files\Models\File;

/**
 * The only place that knows which class reads which file (§17).
 *
 * The same shape as `ProviderRegistry` and for the same reason: adding OCR for
 * scanned pages, or a better spreadsheet reader, is a class registered here
 * and nothing else. `for()` returns null rather than throwing — a file type
 * nobody can read is a sentence for the customer, not an exception on a queue
 * that will retry it three times and fail identically.
 */
class ExtractorRegistry
{
    /** @var array<int, TextExtractor> */
    private array $extractors = [];

    public function __construct()
    {
        $this->register(app(PlainTextExtractor::class));
        $this->register(app(CsvExtractor::class));
        $this->register(app(DocxExtractor::class));
        $this->register(app(PdfExtractor::class));
    }

    public function register(TextExtractor $extractor): void
    {
        $this->extractors[] = $extractor;
    }

    public function for(File $file): ?TextExtractor
    {
        foreach ($this->extractors as $extractor) {
            if ($extractor->handles($file)) {
                return $extractor;
            }
        }

        return null;
    }

    /**
     * Every extension the platform can currently read.
     *
     * Read from the extractors themselves, so the admin screen that lists
     * "what you can put in a knowledge base" can never drift from what the
     * code actually handles.
     *
     * @return array<int, string>
     */
    public function extensions(): array
    {
        $extensions = [];

        foreach ($this->extractors as $extractor) {
            $extensions = array_merge($extensions, $extractor->extensions());
        }

        sort($extensions);

        return array_values(array_unique($extensions));
    }

    /** @return array<int, string> */
    public function keys(): array
    {
        return array_map(fn (TextExtractor $e) => $e->key(), $this->extractors);
    }
}
