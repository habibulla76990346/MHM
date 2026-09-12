<?php

namespace App\Domains\Payments\DTO;

/**
 * What the GATEWAY says happened — the only authority on whether money moved.
 *
 * A browser coming back with "success" in the URL proves nothing: it can be
 * replayed, edited, or simply never arrive. Every path through the payment
 * system converges on a fresh `fetchTransaction()` and believes only this.
 */
final class TransactionStatus
{
    public const PAID = 'paid';

    public const PENDING = 'pending';

    public const FAILED = 'failed';

    public const REFUNDED = 'refunded';

    public const PARTIALLY_REFUNDED = 'partially_refunded';

    public const UNKNOWN = 'unknown';

    public function __construct(
        public readonly string $status,
        public readonly ?string $gatewayPaymentId = null,
        public readonly ?float $amount = null,
        public readonly ?string $currency = null,
        public readonly ?float $settlementAmount = null,
        public readonly ?string $settlementCurrency = null,
        public readonly ?string $failureCode = null,
        public readonly ?string $failureReason = null,
        public readonly ?string $method = null,
        /** @var array<string, mixed> already scrubbed by the adapter */
        public readonly array $raw = [],
    ) {}

    public function isPaid(): bool
    {
        return $this->status === self::PAID;
    }

    public function isSettled(): bool
    {
        return in_array($this->status, [self::PAID, self::FAILED, self::REFUNDED], true);
    }
}
