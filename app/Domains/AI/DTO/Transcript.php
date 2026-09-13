<?php

namespace App\Domains\AI\DTO;

/** What a provider heard. */
final class Transcript
{
    public function __construct(
        public readonly string $text,
        public readonly ?string $language = null,
        /**
         * How long the audio was, when the provider says so.
         *
         * Zero means it did not, and the caller measures it a different way —
         * never invents it, because this figure is what a customer is charged
         * against.
         */
        public readonly float $seconds = 0.0,
    ) {}
}
