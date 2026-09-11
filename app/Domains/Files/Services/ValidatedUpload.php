<?php

namespace App\Domains\Files\Services;

use Illuminate\Http\UploadedFile;

/** A file that has passed every Phase 1 control and may now be stored. */
final class ValidatedUpload
{
    public function __construct(
        public readonly UploadedFile $file,
        public readonly string $extension,
        public readonly string $detectedMime,
        public readonly ?string $declaredMime,
        public readonly string $originalName,
        public readonly int $sizeBytes,
    ) {
    }
}
