<?php

namespace App\Domains\Billing\Support;

use App\Domains\Billing\Models\Plan;

/**
 * What changing plan mid-period actually costs.
 *
 * Kept as a value object so the same arithmetic answers both "what will this
 * cost me?" on the customer's screen and "what do we charge?" at checkout.
 * Two implementations of that sum would eventually disagree, and the customer
 * would be shown one number and charged another.
 */
final class ProrationResult
{
    public function __construct(
        public readonly Plan $from,
        public readonly Plan $to,
        public readonly string $currency,
        /** What the new plan costs for a full period. */
        public readonly float $newPlanAmount,
        /** The unused part of what they already paid. */
        public readonly float $unusedCredit,
        /** What they pay now — never below zero. */
        public readonly float $amountDue,
        /** Credits the change grants immediately, pro-rated for the time left. */
        public readonly float $creditsGranted,
        public readonly bool $isUpgrade,
        /** An upgrade takes effect now; a downgrade waits for the period end. */
        public readonly \DateTimeInterface $effectiveAt,
        public readonly float $unusedFraction,
    ) {}

    public function isImmediate(): bool
    {
        return $this->isUpgrade;
    }

    public function toArray(): array
    {
        return [
            'from' => $this->from->name,
            'to' => $this->to->name,
            'currency' => $this->currency,
            'new_plan_amount' => $this->newPlanAmount,
            'unused_credit' => $this->unusedCredit,
            'amount_due' => $this->amountDue,
            'credits_granted' => $this->creditsGranted,
            'is_upgrade' => $this->isUpgrade,
            'effective_at' => $this->effectiveAt,
        ];
    }
}
