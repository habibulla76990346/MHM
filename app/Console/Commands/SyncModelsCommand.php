<?php

namespace App\Console\Commands;

use App\Domains\AI\Jobs\SyncProviderModelsJob;
use App\Domains\AI\Models\AiProvider;
use App\Domains\AI\Services\ModelSyncService;
use Illuminate\Console\Command;

/**
 * Refresh the model catalog from every provider that publishes one.
 *
 * Runs from the scheduler, and by hand when an owner wants it now.
 */
class SyncModelsCommand extends Command
{
    protected $signature = 'aziv:models:sync
                            {--provider= : Slug of a single provider}
                            {--now : Run inline instead of queueing}';

    protected $description = 'Refresh the AI model catalog from each provider';

    public function handle(ModelSyncService $sync): int
    {
        $providers = AiProvider::usable()
            ->when($this->option('provider'), fn ($q, $slug) => $q->where('slug', $slug))
            ->get();

        if ($providers->isEmpty()) {
            $this->warn('No active providers to sync.');

            return self::SUCCESS;
        }

        foreach ($providers as $provider) {
            if (! $sync->canSync($provider)) {
                // Not a failure: where a provider publishes no model list, the
                // catalog is maintained by hand and that is the design.
                $this->line("  {$provider->name}: no model list published — maintained manually");

                continue;
            }

            if ($this->option('now')) {
                $log = $sync->sync($provider);
                $this->line("  {$provider->name}: {$log->summary()}");

                continue;
            }

            SyncProviderModelsJob::dispatch($provider->getKey());
            $this->line("  {$provider->name}: queued");
        }

        return self::SUCCESS;
    }
}
