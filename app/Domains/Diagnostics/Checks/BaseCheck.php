<?php

namespace App\Domains\Diagnostics\Checks;

use App\Domains\Diagnostics\Contracts\DiagnosticCheck;
use App\Domains\Diagnostics\Support\DeploymentMode;

abstract class BaseCheck implements DiagnosticCheck
{
    public function isApplicable(): bool { return true; }
    public function isSafeToRunAutomatically(): bool { return true; }
    public function hasSideEffects(): bool { return false; }
    public function costsMoney(): bool { return false; }

    protected function mode(): DeploymentMode
    {
        return DeploymentMode::resolve();
    }
}
