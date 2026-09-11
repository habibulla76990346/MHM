<?php

namespace App\Domains\AI\Contracts;

interface SupportsEmbeddings
{
    /**
     * @param  array<int, string>  $inputs
     * @return array<int, array<int, float>>
     */
    public function embed(string $modelIdentifier, array $inputs): array;
}
