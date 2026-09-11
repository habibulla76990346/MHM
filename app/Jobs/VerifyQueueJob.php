<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Proves the queue actually processes work in this environment.
 *
 * On shared hosting there is no persistent worker, so the queue runs in
 * cron-driven bursts (see docs/13-deployment-portability.md §3). This job is
 * what the Phase 0 gate and the cron.heartbeat diagnostic use to confirm the
 * mechanism is alive — it is cheap, idempotent and side-effect free.
 */
class VerifyQueueJob implements ShouldQueue
{
    use Queueable;

    public const CACHE_KEY = 'aziv:queue:last_verified_at';

    public function __construct(public readonly ?string $token = null)
    {
    }

    public function handle(): void
    {
        Cache::put(self::CACHE_KEY, [
            'at' => now()->toIso8601String(),
            'token' => $this->token,
        ], now()->addDays(7));
    }
}
