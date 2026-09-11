<?php

namespace App\Domains\AI\Contracts;

use App\Domains\AI\DTO\TestResult;
use App\Domains\AI\Models\AiProvider;

/**
 * The translator between Aziv AI's format and one provider's API
 * (blueprint §12).
 *
 * Everything above this interface is provider-agnostic. That is what makes
 * adding a provider a configuration task rather than a rewrite — and what
 * `OpenAiCompatibleAdapter` turns into "no code at all" for the large number
 * of providers that copy OpenAI's shape.
 *
 * WHAT THIS DOES NOT PROMISE. Blueprint §12 is explicit, and so is the plan:
 * a provider with a genuinely unusual protocol — binary formats, non-HTTP
 * transport, a bespoke auth handshake — still needs a purpose-built adapter.
 * The guarantee is that writing one touches this single class and nothing else.
 */
interface ProviderAdapter
{
    public function forProvider(AiProvider $provider): static;

    /** @return array<int, string> Capability constants this adapter can serve */
    public function capabilities(): array;

    public function supports(string $capability): bool;

    /**
     * A real exchange with the provider, bounded so a test can never be
     * expensive (§25). Returns a normalised result — never a raw error.
     */
    public function testConnection(): TestResult;
}
