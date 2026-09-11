<?php

namespace App\Domains\AI\Routing;

use App\Domains\AI\Models\AiProvider;
use App\Domains\AI\Models\ProviderCircuitState;
use Illuminate\Support\Facades\Cache;

/**
 * Gives a failing provider a rest (blueprint §14).
 *
 * Retrying a provider that is down wastes the customer's time and the owner's
 * money. Three states:
 *
 *   CLOSED     healthy — traffic flows
 *   OPEN       failed too often — skipped entirely, router goes to the next
 *   HALF-OPEN  cooldown elapsed — ONE probe. Success closes it; failure opens
 *              it again with a longer cooldown
 *
 * STATE LIVES IN THE CACHE and is mirrored to the database. The cache is what
 * the router reads on every request, so the check costs nothing; the mirror is
 * what the Admin Panel displays and what an owner resets. Reading the database
 * on every routing decision would make the breaker itself a bottleneck.
 *
 * The mirror is deliberately best-effort: if writing it fails, routing still
 * works. A breaker that could take the site down by failing to log would be
 * worse than the outage it exists to handle.
 */
class CircuitBreaker
{
    private const CACHE_PREFIX = 'aziv:circuit:';

    public function isOpen(AiProvider $provider): bool
    {
        $state = $this->state($provider);

        if ($state['forced'] ?? false) {
            return true;
        }

        if (($state['status'] ?? ProviderCircuitState::CLOSED) !== ProviderCircuitState::OPEN) {
            return false;
        }

        // An open circuit past its probe time is not open any more — it is
        // ready to be tried once. Treating it as permanently open would mean a
        // recovered provider never came back on its own.
        return ($state['next_probe_at'] ?? 0) > now()->timestamp;
    }

    /** True when the next call is the single probe allowed through. */
    public function isProbing(AiProvider $provider): bool
    {
        $state = $this->state($provider);

        return ($state['status'] ?? '') === ProviderCircuitState::OPEN
            && ! ($state['forced'] ?? false)
            && ($state['next_probe_at'] ?? 0) <= now()->timestamp;
    }

    public function recordSuccess(AiProvider $provider): void
    {
        $state = $this->state($provider);

        // Nothing to do for a provider that was already healthy — and in
        // particular, no write on every successful request.
        if (($state['status'] ?? ProviderCircuitState::CLOSED) === ProviderCircuitState::CLOSED
            && ($state['failures'] ?? 0) === 0) {
            return;
        }

        $this->put($provider, [
            'status' => ProviderCircuitState::CLOSED,
            'failures' => 0,
            'opened_at' => null,
            'next_probe_at' => null,
            'consecutive_opens' => 0,
            'forced' => $state['forced'] ?? false,
        ]);
    }

    public function recordFailure(AiProvider $provider): void
    {
        $state = $this->state($provider);
        $failures = ($state['failures'] ?? 0) + 1;
        $threshold = $this->threshold();

        if ($failures < $threshold) {
            $this->put($provider, ['status' => ProviderCircuitState::CLOSED, 'failures' => $failures] + $state);

            return;
        }

        // Each successive opening waits longer. A provider that keeps failing
        // should be probed less often, not hammered on a fixed schedule.
        $opens = ($state['consecutive_opens'] ?? 0) + 1;
        $cooldown = min($this->cooldown() * (2 ** ($opens - 1)), $this->maxCooldown());

        $this->put($provider, [
            'status' => ProviderCircuitState::OPEN,
            'failures' => $failures,
            'opened_at' => now()->timestamp,
            'next_probe_at' => now()->addSeconds($cooldown)->timestamp,
            'consecutive_opens' => $opens,
            'forced' => $state['forced'] ?? false,
        ]);
    }

    /** An administrator putting a provider back in rotation (§24). */
    public function reset(AiProvider $provider): void
    {
        $this->put($provider, [
            'status' => ProviderCircuitState::CLOSED,
            'failures' => 0,
            'opened_at' => null,
            'next_probe_at' => null,
            'consecutive_opens' => 0,
            'forced' => false,
        ]);
    }

    /** §24 emergency control: take a provider out without disabling it. */
    public function forceOpen(AiProvider $provider, bool $forced = true): void
    {
        $this->put($provider, $this->state($provider) + ['forced' => $forced], forced: $forced);
    }

    /** @return array<string, mixed> */
    public function state(AiProvider $provider): array
    {
        return Cache::get(self::CACHE_PREFIX.$provider->getKey(), [
            'status' => ProviderCircuitState::CLOSED,
            'failures' => 0,
            'consecutive_opens' => 0,
            'forced' => false,
        ]);
    }

    public function describe(AiProvider $provider): string
    {
        $state = $this->state($provider);

        return match (true) {
            (bool) ($state['forced'] ?? false) => 'Forced open by an administrator',
            $this->isProbing($provider) => 'Testing whether it has recovered',
            $this->isOpen($provider) => 'Open — routing around this provider',
            ($state['failures'] ?? 0) > 0 => sprintf('Closed, %d recent failure(s)', $state['failures']),
            default => 'Closed — normal',
        };
    }

    /** @param array<string, mixed> $state */
    private function put(AiProvider $provider, array $state, ?bool $forced = null): void
    {
        $state['forced'] = $forced ?? ($state['forced'] ?? false);

        Cache::put(self::CACHE_PREFIX.$provider->getKey(), $state, now()->addDay());

        $this->mirror($provider, $state);
    }

    /**
     * Mirror to the database so the panel can display it.
     *
     * Best-effort by design: a breaker that could take the site down by
     * failing to write a log row would be worse than the outage it exists to
     * handle.
     */
    private function mirror(AiProvider $provider, array $state): void
    {
        try {
            ProviderCircuitState::updateOrCreate(
                ['provider_id' => $provider->getKey()],
                [
                    'state' => $state['status'] ?? ProviderCircuitState::CLOSED,
                    'failure_count' => min(65535, (int) ($state['failures'] ?? 0)),
                    'opened_at' => ! empty($state['opened_at']) ? now()->setTimestamp($state['opened_at']) : null,
                    'next_probe_at' => ! empty($state['next_probe_at']) ? now()->setTimestamp($state['next_probe_at']) : null,
                    'forced_open' => (bool) ($state['forced'] ?? false),
                ],
            );
        } catch (\Throwable) {
            // Deliberately swallowed. See the note above.
        }
    }

    private function threshold(): int
    {
        return max(1, (int) settings('routing.circuit_failure_threshold'));
    }

    private function cooldown(): int
    {
        return max(5, (int) settings('routing.circuit_cooldown_seconds'));
    }

    private function maxCooldown(): int
    {
        return max($this->cooldown(), (int) settings('routing.circuit_max_cooldown_seconds'));
    }
}
