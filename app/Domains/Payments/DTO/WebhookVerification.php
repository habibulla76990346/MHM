<?php

namespace App\Domains\Payments\DTO;

/**
 * The result of checking a webhook's signature.
 *
 * `valid` is false by default and has to be earned. An adapter that cannot
 * verify its webhooks does not ship — a payment notification nobody
 * authenticated is an instruction from a stranger to grant credits.
 */
final class WebhookVerification
{
    public function __construct(
        public readonly bool $valid,
        public readonly ?string $eventId = null,
        public readonly ?string $eventType = null,
        public readonly ?string $gatewayPaymentId = null,
        public readonly ?string $reference = null,
        /** @var array<string, mixed> */
        public readonly array $payload = [],
        public readonly ?string $reason = null,
    ) {}

    public static function rejected(string $reason): self
    {
        return new self(false, reason: $reason);
    }
}
