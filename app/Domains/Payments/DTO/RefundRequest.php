<?php

namespace App\Domains\Payments\DTO;

final class RefundRequest
{
    public function __construct(
        public readonly string $gatewayPaymentId,
        public readonly float $amount,
        public readonly string $currency,
        public readonly string $reason,
        public readonly string $reference,
    ) {}
}
