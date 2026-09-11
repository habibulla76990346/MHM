<?php

namespace App\Domains\AI\Contracts;

interface SupportsImageGeneration
{
    /** @return array<int, string> image URLs or base64 payloads */
    public function generateImage(string $modelIdentifier, string $prompt, array $options = []): array;
}
