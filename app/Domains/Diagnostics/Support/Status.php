<?php

namespace App\Domains\Diagnostics\Support;

/**
 * The current state of a check.
 *
 * GREY matters as much as RED: on shared hosting an absent Redis is EXPECTED,
 * not broken. Reporting it red would fill the screen with alarms about things
 * working as designed, and an admin who sees permanent red learns to ignore
 * red — which is worse than no diagnostics at all.
 */
enum Status: string
{
    case Green = 'green';   // working
    case Yellow = 'yellow'; // working, but limited or degraded
    case Red = 'red';       // broken
    case Grey = 'grey';     // not configured, or not applicable in this mode

    public function isProblem(): bool
    {
        return $this === self::Red;
    }
}
