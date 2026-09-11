<?php

namespace App\Domains\AI\Routing;

use App\Domains\AI\Models\AiModel;
use App\Domains\AI\Models\RoutingLog;

/**
 * What the router decided, and everything needed to explain it.
 */
final class RoutingDecision
{
    /**
     * @param  array<int, Candidate>  $candidates
     * @param  array<int, string>  $required
     */
    public function __construct(
        public readonly ?AiModel $model,
        public readonly array $candidates,
        public readonly array $required,
        public readonly string $mode,
        public readonly int $fallbackDepth = 0,
        public readonly ?string $reason = null,
        public readonly ?RoutingLog $log = null,
    ) {}

    public function chosen(): bool
    {
        return $this->model !== null;
    }

    /** @return array<int, Candidate> everything still available to fall back to */
    public function alternatives(): array
    {
        return array_values(array_filter(
            $this->candidates,
            fn (Candidate $c) => $c->eligible && $c->model->getKey() !== $this->model?->getKey(),
        ));
    }

    /**
     * Why nothing could serve this request, in words an owner can act on.
     *
     * Built from the ACTUAL rejections rather than a generic message: "every
     * model that could do this is on a provider that is failing" and "you have
     * not enabled any model" need completely different responses.
     */
    public function explainFailure(): string
    {
        $top = $this->dominantRejection();

        if ($top === null) {
            return __('No AI model is set up yet.');
        }

        return RejectionReason::explain($top).'.';
    }

    /**
     * The reason that accounts for most of the rejections.
     *
     * Deliberately the most COMMON one rather than the first: with ten models
     * on a provider that is down, one unrelated rejection at the top of the
     * list would otherwise become the whole explanation.
     */
    public function dominantRejection(): ?string
    {
        $reasons = [];

        foreach ($this->candidates as $candidate) {
            if ($candidate->rejection) {
                $reasons[$candidate->rejection] = ($reasons[$candidate->rejection] ?? 0) + 1;
            }
        }

        if ($reasons === []) {
            return null;
        }

        arsort($reasons);

        return array_key_first($reasons);
    }
}
