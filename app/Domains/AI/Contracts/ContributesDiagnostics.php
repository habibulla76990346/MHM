<?php

namespace App\Domains\AI\Contracts;

use App\Domains\Diagnostics\Support\CheckResult;

/**
 * An adapter that can report on its own health (Owner Addendum G).
 *
 * Phase 3 requires provider diagnostics to be CONTRIBUTED BY EACH ADAPTER
 * rather than written centrally. A central checker would have to know how each
 * provider signals trouble, which is exactly the provider-specific knowledge
 * the adapter layer exists to contain.
 */
interface ContributesDiagnostics
{
    /** @return array<int, CheckResult> */
    public function diagnostics(): array;
}
