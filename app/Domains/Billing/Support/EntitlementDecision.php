<?php

namespace App\Domains\Billing\Support;

/**
 * Whether a customer may do something, and what to tell them if not.
 *
 * A boolean would not be enough: a SOFT limit is exceeded and still allowed,
 * and the difference between "you have reached your daily limit" and "your
 * plan does not include this" is the difference between a customer who waits
 * and a customer who upgrades.
 */
final class EntitlementDecision
{
    private function __construct(
        public readonly bool $allowed,
        public readonly ?string $reason = null,
        public readonly ?float $limit = null,
        public readonly ?float $used = null,
        public readonly bool $isWarning = false,
    ) {}

    public static function allow(?float $limit = null, ?float $used = null): self
    {
        return new self(true, null, $limit, $used);
    }

    /** Over a soft limit: allowed, but the customer should be told. */
    public static function warn(string $reason, ?float $limit = null, ?float $used = null): self
    {
        return new self(true, $reason, $limit, $used, isWarning: true);
    }

    public static function deny(string $reason, ?float $limit = null, ?float $used = null): self
    {
        return new self(false, $reason, $limit, $used);
    }

    public function remaining(): ?float
    {
        return $this->limit === null ? null : max(0.0, $this->limit - (float) $this->used);
    }
}
