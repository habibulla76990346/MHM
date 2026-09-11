<?php

namespace App\Domains\AI\DTO;

/**
 * A model a provider says it offers.
 *
 * Deliberately thin. A discovered model arrives DISABLED and the administrator
 * decides — so nothing here is trusted enough to start costing money on its
 * own.
 */
final class DiscoveredModel
{
    /**
     * @param  array<int, string>  $capabilities
     */
    public function __construct(
        public readonly string $identifier,
        public readonly ?string $displayName = null,
        public readonly ?string $description = null,
        public readonly ?int $contextWindow = null,
        public readonly ?int $maxOutputTokens = null,
        public readonly array $capabilities = [],
        public readonly string $modality = 'text',
    ) {}

    public function name(): string
    {
        return $this->displayName ?: $this->identifier;
    }
}
