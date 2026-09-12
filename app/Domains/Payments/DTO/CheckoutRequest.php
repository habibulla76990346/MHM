<?php

namespace App\Domains\Payments\DTO;

/**
 * What Aziv AI wants collected, in Aziv AI's own vocabulary.
 *
 * No gateway's field names appear here. An adapter translates this into
 * whatever its API expects, which is the whole reason checkout code never has
 * to change when a gateway does.
 */
final class CheckoutRequest
{
    public function __construct(
        public readonly string $reference,
        public readonly float $amount,
        public readonly string $currency,
        public readonly string $description,
        public readonly string $customerEmail,
        public readonly ?string $customerName = null,
        public readonly ?string $customerPhone = null,
        public readonly string $returnUrl = '',
        public readonly string $cancelUrl = '',
        /** @var array<string, string> our own ids, echoed back by the gateway */
        public readonly array $metadata = [],
    ) {}
}
