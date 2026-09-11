<?php

namespace App\Domains\AI\DTO;

use App\Domains\AI\Support\ErrorClass;

/**
 * The answer to "is this provider actually working?" (§25).
 *
 * SECURITY: this object is built from a real API exchange that carried a
 * credential, and it is rendered on an admin screen. It therefore carries a
 * normalised error CLASS and an HTTP status — never a provider's raw message,
 * which on several APIs echoes the failing request back.
 */
final class TestResult
{
    private function __construct(
        public readonly bool $success,
        public readonly int $latencyMs,
        public readonly ?int $httpStatus = null,
        public readonly ?string $errorClass = null,
        public readonly ?string $detail = null,
        public readonly array $context = [],
    ) {}

    public static function pass(int $latencyMs, ?int $httpStatus = 200, array $context = []): self
    {
        return new self(true, $latencyMs, $httpStatus, null, null, $context);
    }

    public static function fail(string $errorClass, int $latencyMs = 0, ?int $httpStatus = null, array $context = []): self
    {
        return new self(
            false,
            $latencyMs,
            $httpStatus,
            $errorClass,
            // The remedy, in words a non-developer can act on — never the
            // provider's own wording.
            ErrorClass::action($errorClass),
            $context,
        );
    }

    public function label(): string
    {
        return $this->success ? 'Working' : ErrorClass::label($this->errorClass ?? ErrorClass::UNKNOWN);
    }

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'latency_ms' => $this->latencyMs,
            'http_status' => $this->httpStatus,
            'error_class' => $this->errorClass,
            'detail' => $this->detail,
            'context' => $this->context,
        ];
    }
}
