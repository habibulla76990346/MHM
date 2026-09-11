<?php

namespace App\Domains\Diagnostics\Contracts;

use App\Domains\Diagnostics\Support\Category;
use App\Domains\Diagnostics\Support\CheckResult;

interface DiagnosticCheck
{
    /** Stable identifier, e.g. 'network.outbound_https'. */
    public function key(): string;

    public function title(): string;

    public function category(): Category;

    /** False => reported GREY with a reason, rather than run and failed. */
    public function isApplicable(): bool;

    public function run(): CheckResult;

    /** Safe for unattended scheduled runs? */
    public function isSafeToRunAutomatically(): bool;

    /** Sends mail, writes data, or otherwise changes something. */
    public function hasSideEffects(): bool;

    /** Costs real money (a live provider call). Manual-only. */
    public function costsMoney(): bool;
}
