<?php

namespace App\Domains\AI\Routing;

use App\Domains\AI\Models\RoutingLog;
use App\Domains\AI\Support\Capability;
use App\Models\User;

/**
 * The router (blueprint §14).
 *
 * Six stages, each doing one thing:
 *
 *   1  CapabilityResolver   what does this request genuinely need?
 *   2  CandidateBuilder     hard filters — every model, every reason recorded
 *   3  CandidateScorer      order the survivors by what this mode cares about
 *   4  attempt + retry      handled by the caller, using RetryPolicy
 *   5  fallback             next candidate, STILL satisfying stage 1
 *   6  record               routing_logs, and usage from the caller
 *
 * The router decides; it does not call providers. Keeping the decision
 * separate from the attempt is what lets the same logic serve chat, images and
 * anything later, and is why a routing decision can be tested without a
 * network.
 */
class AiRouter
{
    public function __construct(
        private readonly CandidateBuilder $builder,
        private readonly CandidateScorer $scorer,
    ) {}

    /**
     * STAGE 1. What the request needs, asked as a question about the REQUEST
     * — never about a model. "There is a picture attached, so it needs vision"
     * is what makes the catalog rather than a model name decide where a
     * request can go (Rule 5).
     *
     * @return array<int, string>
     */
    public function resolveCapabilities(bool $hasImages = false, bool $needsStreaming = false, bool $needsTools = false): array
    {
        $required = [Capability::CHAT];

        if ($hasImages) {
            $required[] = Capability::VISION;
        }

        if ($needsStreaming) {
            $required[] = Capability::STREAMING;
        }

        if ($needsTools) {
            $required[] = Capability::TOOL_USE;
        }

        return $required;
    }

    /**
     * Choose a model.
     *
     * @param  array<int, string>  $required
     * @param  array<int, int>  $excludeModelIds  already tried on this request
     */
    public function route(
        array $required,
        string $mode,
        ?User $user = null,
        ?int $pinnedProviderId = null,
        ?int $pinnedModelId = null,
        int $conversationTokens = 0,
        array $excludeModelIds = [],
        int $fallbackDepth = 0,
        string $requestType = 'chat',
        bool $record = true,
    ): RoutingDecision {
        $mode = RoutingMode::exists($mode) ? $mode : RoutingMode::AUTO;

        $candidates = $this->builder->build(
            $required,
            $mode,
            $pinnedProviderId,
            $pinnedModelId,
            $conversationTokens,
            $excludeModelIds,
        );

        $ranked = $this->scorer->rank($candidates, $mode);
        $selected = $ranked[0] ?? null;

        $reason = $selected
            ? $this->describeChoice($selected, $mode, $fallbackDepth)
            : 'No model satisfied the requirements.';

        $log = $record
            ? $this->record($candidates, $ranked, $required, $mode, $user, $fallbackDepth, $reason, $requestType)
            : null;

        return new RoutingDecision(
            model: $selected?->model,
            candidates: $candidates,
            required: $required,
            mode: $mode,
            fallbackDepth: $fallbackDepth,
            reason: $reason,
            log: $log,
        );
    }

    /**
     * STAGE 5. The next candidate after one has failed.
     *
     * THE CAPABILITY GUARD lives here by construction: this re-runs the whole
     * pipeline with the SAME `$required`, so a fallback is filtered by stage 2
     * exactly as the first choice was. A vision request cannot fall back to a
     * text-only model, because such a model never survives the filter.
     *
     * Doing it any other way — keeping a pre-built list and popping from it —
     * is how that guarantee gets lost, since the list would have been built
     * before anyone knew what would fail.
     */
    public function fallback(RoutingDecision $previous, array $triedModelIds, ?User $user = null, int $conversationTokens = 0): RoutingDecision
    {
        $depth = $previous->fallbackDepth + 1;

        // "One model" means one model. Substituting silently would make the
        // setting a lie.
        if (RoutingMode::forbidsFallback($previous->mode)) {
            return new RoutingDecision(
                model: null,
                candidates: $previous->candidates,
                required: $previous->required,
                mode: $previous->mode,
                fallbackDepth: $depth,
                reason: 'This conversation is pinned to one model, so no substitute was tried.',
            );
        }

        if ($depth > app(RetryPolicy::class)->maxFallbackDepth()) {
            return new RoutingDecision(
                model: null,
                candidates: $previous->candidates,
                required: $previous->required,
                mode: $previous->mode,
                fallbackDepth: $depth,
                reason: 'Reached the maximum number of providers to try.',
            );
        }

        return $this->route(
            required: $previous->required,
            mode: $previous->mode,
            user: $user,
            conversationTokens: $conversationTokens,
            excludeModelIds: $triedModelIds,
            fallbackDepth: $depth,
        );
    }

    private function describeChoice(Candidate $candidate, string $mode, int $depth): string
    {
        $factors = $candidate->factors;

        $description = sprintf(
            '%s via %s — %s (health %.2f, speed %.2f, cost %.2f)',
            $candidate->model->display_name,
            $candidate->model->provider?->name ?? 'unknown provider',
            RoutingMode::label($mode),
            $factors['health'] ?? 0,
            $factors['speed'] ?? 0,
            $factors['cost'] ?? 0,
        );

        return $depth > 0
            ? sprintf('Fallback #%d: %s', $depth, $description)
            : $description;
    }

    /**
     * STAGE 6. Every candidate and every rejection.
     *
     * Best-effort: a routing log that could not be written must not stop a
     * customer getting an answer. The analytics are important; they are not
     * more important than the product working.
     *
     * @param  array<int, Candidate>  $candidates
     * @param  array<int, Candidate>  $ranked
     */
    private function record(
        array $candidates,
        array $ranked,
        array $required,
        string $mode,
        ?User $user,
        int $fallbackDepth,
        string $reason,
        string $requestType,
    ): ?RoutingLog {
        $selected = $ranked[0] ?? null;

        try {
            return RoutingLog::create([
                'user_id' => $user?->getKey(),
                'request_type' => $requestType,
                'routing_mode' => $mode,
                'capability_required' => $required,
                'candidates' => array_map(fn (Candidate $c) => $c->toArray(), $candidates),
                'selected_provider_id' => $selected?->model->provider_id,
                'selected_model_id' => $selected?->model->getKey(),
                'fallback_depth' => $fallbackDepth,
                'decision_reason' => mb_substr($reason, 0, 190),
                'decided_at' => now(),
            ]);
        } catch (\Throwable) {
            return null;
        }
    }
}
