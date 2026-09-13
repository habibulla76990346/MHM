<?php

namespace App\Domains\AI\DTO;

/**
 * Audio to be turned into words.
 *
 * THE BYTES TRAVEL, not a path. An adapter posts them; it never reads a disk,
 * because the file may be on S3 in one deployment and a local disk in another
 * and an adapter that knew the difference would be the one place the
 * "environment is configuration" rule broke.
 */
final class TranscriptionRequest
{
    public function __construct(
        public readonly string $modelIdentifier,
        public readonly string $bytes,
        public readonly string $filename,
        public readonly string $mimeType,
        /** A hint, never a requirement: providers detect it, and a wrong hint is worse than none. */
        public readonly ?string $language = null,
    ) {}
}
