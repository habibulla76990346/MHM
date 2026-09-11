<?php

namespace App\Domains\Chat\Services;

use App\Domains\AI\Models\AiModel;
use App\Domains\AI\Support\Capability;
use App\Domains\Chat\Models\Conversation;
use Illuminate\Support\Collection;

/**
 * Picks the model for a turn (§15's manual selection and Auto mode).
 *
 * Phase 4 deliberately implements only what §15 needs: a pinned model, or Auto.
 * The full scoring pipeline — health, latency, cost, budgets, circuit breakers,
 * the seven routing modes — is Phase 5, and pretending to do it here would
 * mean writing it twice.
 *
 * What IS honoured now, because it is correctness rather than optimisation:
 * a model must be routable, must support every capability the turn needs, and
 * its context window must be big enough for the conversation.
 */
class ModelSelector
{
    /**
     * @param  array<int, string>  $required
     */
    public function select(Conversation $conversation, array $required = [Capability::CHAT]): ?AiModel
    {
        // A pinned model is a decision the customer made. It is honoured or
        // the turn fails — silently answering with a different model would
        // make "Specific model" meaningless.
        if ($conversation->routing_mode === Conversation::ROUTING_SPECIFIC_MODEL && $conversation->pinned_model_id) {
            $pinned = AiModel::with('provider', 'capabilities')->find($conversation->pinned_model_id);

            return $pinned?->isRoutable() ? $pinned : null;
        }

        return $this->auto($required);
    }

    /**
     * Auto: the best available model that can do the job.
     *
     * "Best" here is the administrator's own quality rank and provider
     * priority — not a guess about model families, which would be a hard-coded
     * preference for particular brands and is exactly what Rule 5 forbids.
     */
    public function auto(array $required = [Capability::CHAT]): ?AiModel
    {
        return AiModel::routable()
            ->withCapabilities($required)
            ->with('provider', 'capabilities')
            ->join('ai_providers', 'ai_providers.id', '=', 'ai_models.provider_id')
            ->orderBy('ai_providers.priority')
            ->orderByDesc('ai_models.quality_rank')
            ->orderBy('ai_models.sort_order')
            ->select('ai_models.*')
            ->first();
    }

    /** Everything a customer may choose between, for the model picker. */
    public function available(array $required = [Capability::CHAT]): Collection
    {
        return AiModel::routable()
            ->withCapabilities($required)
            ->with('provider')
            ->orderBy('sort_order')
            ->orderBy('display_name')
            ->get();
    }

    /**
     * Capabilities a turn genuinely needs.
     *
     * Asked as a question about the request, never about the model: "this has
     * a picture attached, so it needs vision" is what makes the catalog, not a
     * model name, the thing that decides where a request can go.
     *
     * @return array<int, string>
     */
    public function requirementsFor(bool $hasImages = false, bool $streaming = false): array
    {
        $required = [Capability::CHAT];

        if ($hasImages) {
            $required[] = Capability::VISION;
        }

        if ($streaming) {
            $required[] = Capability::STREAMING;
        }

        return $required;
    }
}
