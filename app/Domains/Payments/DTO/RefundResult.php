<?php

namespace App\Domains\Payments\DTO;

final class RefundResult
{
    public const PENDING = 'pending';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public function __construct(
        public readonly string $status,
        public readonly ?string $gatewayRefundId = null,
        public readonly ?float $amount = null,
        public readonly ?string $failureReason = null,
    ) {}

    public function succeeded(): bool
    {
        return in_array($this->status, [self::COMPLETED, self::PENDING], true);
    }
}
