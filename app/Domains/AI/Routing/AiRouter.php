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
     * STAGE 5 — the substitute after a failure — is NOT here.
     *
     * It lives in `ChatService::substitute()`, which is the only thing that
     * knows an attempt failed. What matters is HOW it does it: it calls
     * `route()` above again with the same requirement and the failed models
     * excluded, rather than popping from a list built in advance.
     *
     * THE CAPABILITY GUARD follows from that. A substitute is filtered by
     * stage 2 exactly as the first choice was, so a vision request cannot fall
     * back to a model that cannot see — not because anyone checks, but because
     * such a model never survives the filter. A pre-built list would lose the
     * guarantee, since it would have been built before anyone knew what would
     * fail.
     *
     * A second copy of that logic once lived here, unreachable. Two copies of
     * a safety guarantee is one copy and one thing that drifts, so this note
     * replaced it: there is one substitution path, and the multi-provider gate
     * runs against it.
     */
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
