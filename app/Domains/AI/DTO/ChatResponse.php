<?php

namespace App\Domains\AI\DTO;

/**
 * A reply, normalised. The chat system never learns which provider answered
 * — that is the whole point of the adapter layer (§12).
 */
final class ChatResponse
{
    public function __construct(
        public readonly string $content,
        public readonly UsageMetrics $usage,
        public readonly string $modelIdentifier,
        public readonly ?string $finishReason = null,
        public readonly int $latencyMs = 0,
        public readonly array $raw = [],
    ) {}

    public function toArray(): array
    {
        return [
            'content' => $this->content,
            'usage' => $this->usage->toArray(),
            'model' => $this->modelIdentifier,
            'finish_reason' => $this->finishReason,
            'latency_ms' => $this->latencyMs,
        ];
    }
}
