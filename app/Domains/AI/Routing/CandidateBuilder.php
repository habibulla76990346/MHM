<?php

namespace App\Domains\AI\Routing;

use App\Domains\AI\Models\AiModel;
use App\Domains\AI\Models\AiProvider;
use App\Domains\AI\Services\ProviderRegistry;

/**
 * STAGE 2 (blueprint §14): the hard filters.
 *
 * A model survives only if EVERY condition holds. These are not preferences to
 * be traded off in scoring — each one means the request would fail, cost money
 * it should not, or break a promise the owner made.
 *
 * Rejections are kept rather than discarded, because "why was this model not
 * used?" is the question the routing log exists to answer.
 */
class CandidateBuilder
{
    public function __construct(
        private readonly CircuitBreaker $breaker,
        private readonly ProviderRegistry $registry,
    ) {}

    /**
     * @param  array<int, string>  $required
     * @param  array<int, int>  $excludeModelIds  already tried and failed on this request
     * @return array<int, Candidate>
     */
    public function build(
        array $required,
        string $mode,
        ?int $pinnedProviderId = null,
        ?int $pinnedModelId = null,
        int $conversationTokens = 0,
        array $excludeModelIds = [],
    ): array {
        $models = AiModel::with(['provider.budgets', 'capabilities'])
            // Deliberately NOT ->routable(): a model excluded by that scope
            // would vanish from the log with no reason recorded. Every model
            // is considered and every rejection is explained.
            ->when($pinnedModelId, fn ($q) => $q->where('id', $pinnedModelId))
            ->get();

        $candidates = [];

        foreach ($models as $model) {
            $candidates[] = $this->assess(
                $model,
                $required,
                $mode,
                $pinnedProviderId,
                $conversationTokens,
                $excludeModelIds,
            );
        }

        return $candidates;
    }

    /** @param array<int, string> $required */
    private function assess(
        AiModel $model,
        array $required,
        string $mode,
        ?int $pinnedProviderId,
        int $conversationTokens,
        array $excludeModelIds,
    ): Candidate {
        $provider = $model->provider;

        if (! $provider) {
            return Candidate::rejected($model, RejectionReason::PROVIDER_DISABLED);
        }

        if (in_array($model->getKey(), $excludeModelIds, true)) {
            return Candidate::rejected($model, RejectionReason::ALREADY_TRIED);
        }

        if ($pinnedProviderId && $provider->getKey() !== $pinnedProviderId) {
            return Candidate::rejected($model, RejectionReason::WRONG_PROVIDER);
        }

        if ($provider->status !== AiProvider::STATUS_ACTIVE) {
            return Candidate::rejected($model, RejectionReason::PROVIDER_DISABLED);
        }

        if ($provider->maintenance_mode) {
            return Candidate::rejected($model, RejectionReason::PROVIDER_MAINTENANCE);
        }

        if (! $model->is_enabled) {
            return Candidate::rejected($model, RejectionReason::MODEL_DISABLED);
        }

        if (in_array($model->status, [AiModel::STATUS_DEPRECATED, AiModel::STATUS_DISABLED], true)) {
            return Candidate::rejected($model, RejectionReason::MODEL_DEPRECATED);
        }

        // THE CAPABILITY GUARD. This is what stops a vision request falling
        // back to a text-only model — the failure mode §14 calls out by name,
        // because the request would not error, it would just answer wrongly.
        foreach ($required as $capability) {
            if (! $model->supports($capability)) {
                return Candidate::rejected($model, RejectionReason::MISSING_CAPABILITY);
            }
        }

        if (! $this->registry->for($provider)) {
            return Candidate::rejected($model, RejectionReason::NO_ADAPTER);
        }

        if (! $provider->activeCredential()) {
            return Candidate::rejected($model, RejectionReason::NO_CREDENTIAL);
        }

        if ($this->breaker->isOpen($provider)) {
            return Candidate::rejected($model, RejectionReason::CIRCUIT_OPEN);
        }

        // Only a budget set to BLOCK removes a candidate. "Warn" means the
        // owner wants to know, not to stop serving customers.
        foreach ($provider->budgets as $budget) {
            if ($budget->blocksUse()) {
                return Candidate::rejected($model, RejectionReason::BUDGET_EXHAUSTED);
            }
        }

        if ($conversationTokens > 0
            && $model->context_window
            && $conversationTokens > $model->context_window) {
            return Candidate::rejected($model, RejectionReason::CONTEXT_TOO_SMALL);
        }

        if ($mode === RoutingMode::FREE_ONLY && $provider->account_class !== 'free') {
            return Candidate::rejected($model, RejectionReason::NOT_FREE_TIER);
        }

        return Candidate::eligible($model);
    }
}
