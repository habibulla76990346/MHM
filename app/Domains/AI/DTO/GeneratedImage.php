<?php

namespace App\Domains\AI\DTO;

/**
 * One image as a provider handed it back.
 *
 * EITHER BYTES OR A URL, never both required. Providers differ: some return
 * base64 inline, some return a short-lived URL on their own CDN. Normalising
 * that difference here means the service that stores the image does not care
 * which company answered — and the adapter never has to make a second HTTP
 * call the router did not authorise.
 */
final class GeneratedImage
{
    public function __construct(
        public readonly ?string $bytes = null,
        public readonly ?string $url = null,
        public readonly ?string $mimeType = null,
        /** What the provider actually generated from, when it rewrote the prompt. */
        public readonly ?string $revisedPrompt = null,
    ) {}

    public static function fromBase64(string $encoded, ?string $mimeType = null, ?string $revisedPrompt = null): self
    {
        return new self(
            bytes: base64_decode($encoded, true) ?: null,
            mimeType: $mimeType,
            revisedPrompt: $revisedPrompt,
        );
    }

    public function hasBytes(): bool
    {
        return $this->bytes !== null && $this->bytes !== '';
    }
}
