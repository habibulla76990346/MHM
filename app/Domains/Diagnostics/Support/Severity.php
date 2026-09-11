<?php

namespace App\Domains\Diagnostics\Support;

/**
 * How much a finding matters. Separate from Status (the current state) —
 * conflating them loses information: a GREY item can carry High severity
 * (a payment gateway with no credentials, when you intend to charge).
 */
enum Severity: string
{
    case Critical = 'critical';         // Aziv AI cannot function
    case High = 'high';                 // a major feature is broken
    case Medium = 'medium';             // degraded or risky
    case Low = 'low';                   // worth improving
    case Informational = 'informational';

    public function rank(): int
    {
        return match ($this) {
            self::Critical => 0,
            self::High => 1,
            self::Medium => 2,
            self::Low => 3,
            self::Informational => 4,
        };
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
