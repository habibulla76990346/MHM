<?php

namespace App\Domains\AI\DTO;

/** Audio a provider produced, as bytes. */
final class SynthesisedSpeech
{
    public function __construct(
        public readonly string $bytes,
        public readonly ?string $mimeType = null,
    ) {}
}
