<?php

namespace App\Domains\AI\DTO;

/**
 * Words to be read aloud.
 *
 * `voice` IS A PLAIN STRING AND IS THE ONE PROVIDER-SHAPED VALUE HERE. Every
 * provider names its voices differently and there is no common vocabulary to
 * translate into; an owner picks one from what their provider offers and it is
 * passed through. Null means the provider's own default, which is what an
 * owner who has not chosen should get.
 */
final class SpeechRequest
{
    public function __construct(
        public readonly string $modelIdentifier,
        public readonly string $text,
        public readonly ?string $voice = null,
        /** mp3 | wav | opus — what the platform can store and a browser can play. */
        public readonly string $format = 'mp3',
    ) {}
}
