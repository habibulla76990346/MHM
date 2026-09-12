<?php

namespace App\Domains\Payments\DTO;

/**
 * The result of an admin "test this connection" click.
 *
 * NEVER carries the credential, and never the gateway's raw error text: some
 * APIs echo the failing request back, and that request carried the key. A
 * class and a latency is enough to act on.
 */
final class GatewayTestResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly int $latencyMs = 0,
        public readonly ?string $errorClass = null,
        public readonly ?string $advice = null,
    ) {}

    public static function pass(int $latencyMs): self
    {
        return new self(true, $latencyMs);
    }

    public static function fail(string $errorClass, string $advice, int $latencyMs = 0): self
    {
        return new self(false, $latencyMs, $errorClass, $advice);
    }
}
