<?php

namespace App\Domains\Chat\Services;

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Per-customer message rate limiting (§15).
 *
 * This protects the OWNER'S PROVIDER BILL, not the server. A runaway script or
 * an impatient loop can spend real money in seconds, and unlike a normal rate
 * limit the cost is not "some CPU" — it is an invoice from OpenAI.
 *
 * Uses Laravel's cache-backed limiter, so it works on the database driver that
 * shared hosting uses as well as on Redis. Capability does not differ; only
 * speed does.
 */
class ChatRateLimiter
{
    public function key(User $user): string
    {
        return 'chat:'.$user->getKey();
    }

    public function tooManyAttempts(User $user): bool
    {
        return RateLimiter::tooManyAttempts($this->key($user), $this->perMinute());
    }

    public function hit(User $user): void
    {
        RateLimiter::hit($this->key($user), 60);
    }

    public function availableIn(User $user): int
    {
        return RateLimiter::availableIn($this->key($user));
    }

    public function remaining(User $user): int
    {
        return RateLimiter::remaining($this->key($user), $this->perMinute());
    }

    public function clear(User $user): void
    {
        RateLimiter::clear($this->key($user));
    }

    private function perMinute(): int
    {
        return max(1, (int) settings('chat.rate_limit_per_minute'));
    }
}
