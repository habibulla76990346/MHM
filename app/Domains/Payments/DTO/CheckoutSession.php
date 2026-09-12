<?php

namespace App\Domains\Payments\DTO;

use App\Domains\Payments\Support\CheckoutMode;

/**
 * Everything the browser needs to start paying, whichever shape the gateway
 * uses.
 *
 * `publicConfig` is deliberately named: only values that are SAFE IN A PAGE go
 * in it — a merchant identifier, an order id, an amount. A secret never
 * reaches this object, so it can never reach a template by accident.
 */
final class CheckoutSession
{
    public function __construct(
        public readonly string $gatewayReference,
        public readonly string $mode,
        public readonly ?string $redirectUrl = null,
        /** @var array<string, mixed> safe to render into the checkout page */
        public readonly array $publicConfig = [],
        public readonly ?string $orderId = null,
        public readonly int $latencyMs = 0,
    ) {}

    public function leavesSite(): bool
    {
        return CheckoutMode::leavesSite($this->mode);
    }
}
