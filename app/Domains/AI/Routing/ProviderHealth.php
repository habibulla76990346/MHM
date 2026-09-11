<?php

namespace App\Domains\AI\Routing;

use App\Domains\AI\Models\ProviderHealthLog;
use Illuminate\Support\Facades\Cache;

/**
 * How each provider is actually performing (blueprint §14).
 *
 * Samples come from REAL TRAFFIC, not synthetic pings. A ping tells you a
 * provider's status page is up; what a customer experiences is the latency of
 * the request they just made, on the model they actually used, from this
 * server. Only the second one is worth routing on.
 *
 * Latency is reported as a MEDIAN rather than a mean: one 30-second timeout
 * would drag an average far enough to make a good provider look bad, which is
 * exactly the wrong response to a single bad sample.
 *
 * Cached briefly. Routing reads this on every request, and a percentile over a
 * day of traffic is not a query to run per message.
 */
class ProviderHealth
{
    private const CACHE_PREFIX = 'aziv:health:';

    private const CACHE_SECONDS = 60;

    /**
     * @return array{samples: int, success_rate: float, p50_latency_ms: int|null}
     */
    public function for(int $providerId): array
    {
        return Cache::remember(
            self::CACHE_PREFIX.$providerId.':'.$this->windowHours(),
            self::CACHE_SECONDS,
            fn () => $this->measure($providerId),
        );
    }

    public function successRate(int $providerId): float
    {
        return $this->for($providerId)['success_rate'];
    }

    public function p50Latency(int $providerId): ?int
    {
        return $this->for($providerId)['p50_latency_ms'];
    }

    public function flush(?int $providerId = null): void
    {
        if ($providerId) {
            Cache::forget(self::CACHE_PREFIX.$providerId.':'.$this->windowHours());

            return;
        }

        // No tag support on the database cache driver that shared hosting
        // uses, so flushing everything means letting the short TTL expire.
    }

    /** @return array{samples: int, success_rate: float, p50_latency_ms: int|null} */
    private function measure(int $providerId): array
    {
        $since = now()->subHours($this->windowHours());

        $rows = ProviderHealthLog::where('provider_id', $providerId)
            ->where('checked_at', '>=', $since)
            ->get(['success', 'latency_ms']);

        if ($rows->isEmpty()) {
            // No evidence is not the same as bad evidence. A provider nobody
            // has used yet must not be scored as if it had failed — that would
            // make a newly added provider permanently unreachable.
            return ['samples' => 0, 'success_rate' => 1.0, 'p50_latency_ms' => null];
        }

        $latencies = $rows->where('success', true)
            ->pluck('latency_ms')
            ->filter(fn ($v) => $v !== null && $v > 0)
            ->sort()
            ->values();

        return [
            'samples' => $rows->count(),
            'success_rate' => round($rows->where('success', true)->count() / $rows->count(), 4),
            'p50_latency_ms' => $latencies->isEmpty()
                ? null
                : (int) $latencies[(int) floor(($latencies->count() - 1) / 2)],
        ];
    }

    private function windowHours(): int
    {
        return max(1, (int) settings('routing.health_window_hours'));
    }
}
