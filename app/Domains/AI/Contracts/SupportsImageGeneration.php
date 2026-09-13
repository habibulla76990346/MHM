<?php

namespace App\Domains\AI\Contracts;

use App\Domains\AI\DTO\GeneratedImage;
use App\Domains\AI\DTO\ImageRequest;

/**
 * An adapter that can make pictures (§16).
 *
 * ONE CALL, N IMAGES. Providers charge and rate-limit per request, so asking
 * for four in one call is both cheaper and less likely to be throttled than
 * four calls — and it is what makes a partial result ("three of the four came
 * back") a case the caller can settle credits against honestly.
 */
interface SupportsImageGeneration
{
    /** @return array<int, GeneratedImage> */
    public function generateImage(ImageRequest $request): array;
}
