<?php

namespace App\Domains\AI\Routing;

use App\Domains\AI\Models\AiModel;

/**
 * One model the router considered, with its verdict.
 *
 * Kept as a small object rather than an array so the reason a model was
 * rejected travels with it all the way into the log — the point of recording
 * candidates at all is that nothing is reconstructed afterwards.
 */
final class Candidate
{
    private function __construct(
        public readonly AiModel $model,
        public readonly bool $eligible,
        public readonly ?string $rejection = null,
        public float $score = 0.0,
        /** @var array<string, mixed> what the score was built from */
        public array $factors = [],
    ) {}

    public static function eligible(AiModel $model): self
    {
        return new self($model, true);
    }

    public static function rejected(AiModel $model, string $reason): self
    {
        return new self($model, false, $reason);
    }

    public function withScore(float $score, array $factors = []): self
    {
        $this->score = $score;
        $this->factors = $factors;

        return $this;
    }

    /** @return array<string, mixed> the row that goes into routing_logs */
    public function toArray(): array
    {
        return array_filter([
            'model_id' => $this->model->getKey(),
            'model' => $this->model->model_identifier,
            'provider' => $this->model->provider?->name,
            'eligible' => $this->eligible,
            'rejected_because' => $this->rejection ? RejectionReason::explain($this->rejection) : null,
            'rejection_code' => $this->rejection,
            'score' => $this->eligible ? round($this->score, 4) : null,
            'factors' => $this->factors ?: null,
        ], fn ($value) => $value !== null);
    }
}
