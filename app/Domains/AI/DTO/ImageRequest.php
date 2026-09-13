<?php

namespace App\Domains\AI\DTO;

/**
 * What the application asks for when it wants a picture.
 *
 * The vocabulary is Aziv AI's, not any provider's: a size string, a quality
 * word, an optional style. An adapter translates them, which is the whole
 * reason nothing above the adapter layer knows what a given company calls
 * "hd" this month.
 */
final class ImageRequest
{
    public function __construct(
        public readonly string $modelIdentifier,
        public readonly string $prompt,
        public readonly int $count = 1,
        public readonly string $size = '1024x1024',
        public readonly string $quality = 'standard',
        public readonly ?string $style = null,
        public readonly ?string $negativePrompt = null,
    ) {}

    /** Width and height, for an adapter whose API takes them separately. */
    public function dimensions(): array
    {
        $parts = explode('x', $this->size);

        return [
            'width' => (int) ($parts[0] ?? 1024),
            'height' => (int) ($parts[1] ?? 1024),
        ];
    }

    public function aspectRatio(): string
    {
        ['width' => $w, 'height' => $h] = $this->dimensions();

        if ($w === $h) {
            return '1:1';
        }

        $divisor = static function (int $a, int $b): int {
            while ($b !== 0) {
                [$a, $b] = [$b, $a % $b];
            }

            return max($a, 1);
        };

        $gcd = $divisor($w, $h);

        return ($w / $gcd).':'.($h / $gcd);
    }
}
