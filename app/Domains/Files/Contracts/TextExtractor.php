<?php

namespace App\Domains\Files\Contracts;

use App\Domains\Files\Extraction\ExtractedText;
use App\Domains\Files\Models\File;

/**
 * Turning one uploaded file into text a model can read (§17).
 *
 * AN EXTENSIBLE LAYER, exactly like `FileScanner` and `ProviderAdapter`, and
 * for the same reason: extraction quality varies enormously by file, and the
 * honest position is that today's extractor is the best one available rather
 * than the only one conceivable. A better PDF reader — or an OCR pass for
 * scanned pages — is a new class registered here, with nothing above it
 * changing.
 *
 * An extractor NEVER throws for a file it simply cannot read. It returns an
 * `ExtractedText` that says so, because "this PDF is a scan and has no text
 * layer" is information the customer needs, not an error the queue should
 * retry three times.
 */
interface TextExtractor
{
    /** Stable key, recorded on the document so support can see what read it. */
    public function key(): string;

    /** @return array<int, string> lower-case extensions this handles */
    public function extensions(): array;

    public function handles(File $file): bool;

    /** @param string $path an absolute path to a readable local copy */
    public function extract(File $file, string $path): ExtractedText;
}
