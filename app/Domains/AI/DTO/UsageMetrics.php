<?php

namespace App\Domains\AI\DTO;

/**
 * What a call consumed. Feeds both the customer's credit charge and the
 * owner's margin reporting (§13, §21), which is why provider cost and credit
 * cost are always computed from a DATED price rather than today's.
 */
final class UsageMetrics
{
    public function __construct(
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly int $images = 0,
        public readonly float $seconds = 0.0,
        public readonly int $requests = 1,
    ) {}

    public function totalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }

    public function toArray(): array
    {
        return [
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'total_tokens' => $this->totalTokens(),
            'images' => $this->images,
            'seconds' => $this->seconds,
            'requests' => $this->requests,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (int) ($data['input_tokens'] ?? 0),
            (int) ($data['output_tokens'] ?? 0),
            (int) ($data['images'] ?? 0),
            (float) ($data['seconds'] ?? 0),
            (int) ($data['requests'] ?? 1),
        );
    }
}
