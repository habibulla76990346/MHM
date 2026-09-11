<?php

namespace App\Domains\AI\Contracts;

use App\Domains\AI\DTO\DiscoveredModel;

/**
 * Implemented only where a provider genuinely exposes a model-listing
 * endpoint.
 *
 * This is what makes Rule 5 workable in practice: where the list can be read,
 * Aziv AI reads it; where it cannot, the adapter simply does not implement
 * this interface and the catalog is maintained by hand. No pretending, and no
 * hard-coded model list standing in for a real one.
 */
interface SupportsModelDiscovery
{
    /** @return array<int, DiscoveredModel> */
    public function listModels(): array;
}
