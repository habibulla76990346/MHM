<?php

namespace App\Domains\AI\Services;

use App\Domains\AI\Contracts\SupportsModelDiscovery;
use App\Domains\AI\DTO\DiscoveredModel;
use App\Domains\AI\Exceptions\ProviderFailed;
use App\Domains\AI\Models\AiModel;
use App\Domains\AI\Models\AiModelCapability;
use App\Domains\AI\Models\AiModelSyncLog;
use App\Domains\AI\Models\AiProvider;
use App\Domains\AI\Support\ErrorClass;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the catalog in step with what a provider actually offers (§11).
 *
 * THREE RULES, each of which exists because the obvious alternative causes a
 * real problem:
 *
 *  1. A NEW MODEL ARRIVES DISABLED. Providers add models constantly. If a sync
 *     enabled them, a provider's release schedule would decide what an owner's
 *     customers can spend money on, without the owner ever seeing it.
 *
 *  2. A MODEL THAT DISAPPEARS IS MARKED DEPRECATED, NEVER DELETED. Usage
 *     records, invoices and analytics refer to it. Deleting it would orphan
 *     that history and silently change past reports.
 *
 *  3. A MANUALLY ADDED MODEL IS NEVER OVERWRITTEN. An administrator who typed
 *     a model in did so for a reason — often because the provider does not
 *     list it — and a sync must not undo their work.
 */
class ModelSyncService
{
    public function __construct(private readonly ProviderRegistry $registry) {}

    /**
     * Can this provider's catalog be read automatically?
     *
     * Where it cannot, the adapter simply does not implement the discovery
     * interface and the catalog is maintained by hand. No pretending, and no
     * hard-coded list standing in for a real one (Rule 5).
     */
    public function canSync(AiProvider $provider): bool
    {
        return $this->registry->for($provider) instanceof SupportsModelDiscovery;
    }

    public function sync(AiProvider $provider, ?int $actorId = null): AiModelSyncLog
    {
        $log = AiModelSyncLog::create([
            'provider_id' => $provider->getKey(),
            'started_at' => now(),
            'status' => AiModelSyncLog::STATUS_RUNNING,
            'triggered_by' => $actorId,
        ]);

        $adapter = $this->registry->for($provider);

        if (! $adapter instanceof SupportsModelDiscovery) {
            return $this->finishFailed($log, 'This provider does not publish a model list, so the catalog is maintained by hand.');
        }

        try {
            $discovered = $adapter->listModels();
        } catch (ProviderFailed $e) {
            return $this->finishFailed($log, ErrorClass::action($e->errorClass));
        } catch (\Throwable) {
            return $this->finishFailed($log, ErrorClass::action(ErrorClass::UNKNOWN));
        }

        $added = 0;
        $updated = 0;
        $seen = [];

        DB::transaction(function () use ($provider, $discovered, &$added, &$updated, &$seen) {
            foreach ($discovered as $model) {
                $seen[] = $model->identifier;
                $existing = AiModel::withTrashed()
                    ->where('provider_id', $provider->getKey())
                    ->where('model_identifier', $model->identifier)
                    ->first();

                if ($existing) {
                    $updated += $this->refresh($existing, $model) ? 1 : 0;

                    continue;
                }

                $this->create($provider, $model);
                $added++;
            }
        });

        $deprecated = $this->deprecateMissing($provider, $seen);

        $log->forceFill([
            'finished_at' => now(),
            'status' => AiModelSyncLog::STATUS_SUCCESS,
            'models_added' => $added,
            'models_updated' => $updated,
            'models_deprecated' => $deprecated,
            // A digest, not the payload: a provider's raw response is large
            // and can echo request content back.
            'raw_response_digest' => hash('sha256', implode('|', $seen)),
        ])->save();

        return $log->fresh();
    }

    private function create(AiProvider $provider, DiscoveredModel $discovered): AiModel
    {
        $model = AiModel::create([
            'provider_id' => $provider->getKey(),
            'model_identifier' => $discovered->identifier,
            'display_name' => $discovered->name(),
            'description' => $discovered->description,
            'status' => AiModel::STATUS_STABLE,
            'modality' => $discovered->modality,
            'context_window' => $discovered->contextWindow,
            'max_output_tokens' => $discovered->maxOutputTokens,
            // Rule 1 above. The owner decides what their customers can use.
            'is_enabled' => false,
            'discovered_at' => now(),
            'last_seen_at' => now(),
            'source' => AiModel::SOURCE_SYNCED,
        ]);

        $capabilities = $discovered->capabilities
            ?: AiModel::capabilitiesForModality($discovered->modality);

        foreach ($capabilities as $capability) {
            AiModelCapability::create([
                'model_id' => $model->getKey(),
                'capability' => $capability,
                'is_supported' => true,
            ]);
        }

        return $model;
    }

    /** @return bool whether anything actually changed */
    private function refresh(AiModel $model, DiscoveredModel $discovered): bool
    {
        $model->last_seen_at = now();

        // A model that vanished and came back is available again — but it
        // stays disabled until the owner says otherwise.
        if ($model->status === AiModel::STATUS_DEPRECATED) {
            $model->status = AiModel::STATUS_STABLE;
        }

        if ($model->trashed()) {
            $model->restore();
        }

        // Rule 3 above: a manually added model is the administrator's, and a
        // sync only records that it is still there.
        if ($model->source === AiModel::SOURCE_MANUAL) {
            $model->save();

            return false;
        }

        // Only fill gaps. An administrator who renamed a model or corrected a
        // context window must not have it overwritten on the next sync.
        $model->description ??= $discovered->description;
        $model->context_window ??= $discovered->contextWindow;
        $model->max_output_tokens ??= $discovered->maxOutputTokens;

        $changed = $model->isDirty(['description', 'context_window', 'max_output_tokens', 'status']);

        $model->save();

        return $changed;
    }

    /**
     * Models the provider no longer lists.
     *
     * Marked deprecated and disabled — never deleted (rule 2 above). Manually
     * added models are skipped entirely: the provider not listing one is
     * exactly why an administrator added it by hand.
     */
    private function deprecateMissing(AiProvider $provider, array $seen): int
    {
        return AiModel::where('provider_id', $provider->getKey())
            ->where('source', AiModel::SOURCE_SYNCED)
            ->where('status', '!=', AiModel::STATUS_DEPRECATED)
            ->when($seen !== [], fn ($q) => $q->whereNotIn('model_identifier', $seen))
            ->update([
                'status' => AiModel::STATUS_DEPRECATED,
                'is_enabled' => false,
                'updated_at' => now(),
            ]);
    }

    private function finishFailed(AiModelSyncLog $log, string $message): AiModelSyncLog
    {
        $log->forceFill([
            'finished_at' => now(),
            'status' => AiModelSyncLog::STATUS_FAILED,
            // The remedy in plain language, never a provider's raw error.
            'error_message' => $message,
        ])->save();

        return $log->fresh();
    }
}
