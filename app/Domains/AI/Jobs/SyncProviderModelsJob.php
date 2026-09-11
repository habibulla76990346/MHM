<?php

namespace App\Domains\AI\Jobs;

use App\Domains\AI\Models\AiProvider;
use App\Domains\AI\Services\ModelSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Refreshing a catalog is a network round trip to a third party, so it is a
 * QUEUED JOB.
 *
 * The standing rule: every long operation is queued, so shared hosting differs
 * from a cloud VPS in speed, never in capability. On shared hosting the
 * database queue driver runs this from cron; on a VPS a worker picks it up
 * immediately. Same code, same outcome.
 */
class SyncProviderModelsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(
        public readonly int $providerId,
        public readonly ?int $actorId = null,
    ) {}

    public function handle(ModelSyncService $sync): void
    {
        $provider = AiProvider::find($this->providerId);

        if (! $provider) {
            return;
        }

        $sync->sync($provider, $this->actorId);
    }

    /** One sync per provider at a time — two would fight over the same rows. */
    public function uniqueId(): string
    {
        return 'provider-sync-'.$this->providerId;
    }
}
