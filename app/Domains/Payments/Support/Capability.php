<?php

namespace App\Domains\Payments\Support;

/**
 * What a gateway can actually do (Addendum D §2).
 *
 * Nothing assumes every gateway does everything. The one that matters most is
 * RECURRING: routing a monthly subscription to a gateway that cannot renew it
 * either fails at checkout or, far worse, quietly becomes a one-off payment
 * that never charges again — and nobody notices until the month the money does
 * not arrive.
 */
class Capability
{
    public const ONE_TIME = 'one_time';

    public const RECURRING = 'recurring';

    public const MANDATE = 'mandate';

    public const REFUNDS = 'refunds';

    public const PARTIAL_REFUNDS = 'partial_refunds';

    public const TOKENIZATION = 'tokenization';

    public const INTERNATIONAL = 'international';

    public const PAYMENT_LINKS = 'payment_links';

    /** @return array<string, string> */
    public static function all(): array
    {
        return [
            self::ONE_TIME => 'Single payments',
            self::RECURRING => 'Renews a subscription by itself',
            self::MANDATE => 'Auto-debit under a mandate',
            self::REFUNDS => 'Refunds',
            self::PARTIAL_REFUNDS => 'Partial refunds',
            self::TOKENIZATION => 'Saved cards (tokenised)',
            self::INTERNATIONAL => 'Payments from outside the home country',
            self::PAYMENT_LINKS => 'Payment links',
        ];
    }

    public static function label(string $capability): string
    {
        return self::all()[$capability] ?? $capability;
    }

    /**
     * What a given kind of payment genuinely requires.
     *
     * Asked of the REQUEST, never of the gateway — the same shape the AI
     * router uses, and for the same reason: it is the only way a selector can
     * refuse to substitute something that cannot do the job.
     *
     * @return array<int, string>
     */
    public static function requiredFor(string $paymentType, string $renewalMechanism = 'manual'): array
    {
        return match (true) {
            $paymentType !== 'subscription' => [self::ONE_TIME],
            $renewalMechanism === 'gateway_subscription' => [self::RECURRING],
            $renewalMechanism === 'mandate' => [self::MANDATE],
            // Invoice-and-pay renewal only ever needs a single payment at a
            // time, which is what makes manual renewal a legitimate way to
            // sell a plan through a one-time-only gateway.
            default => [self::ONE_TIME],
        };
    }
}
