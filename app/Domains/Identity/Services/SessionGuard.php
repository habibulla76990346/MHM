<?php

namespace App\Domains\Identity\Services;

use App\Domains\Identity\Models\UserSession;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Session limits and idle timeout (blueprint §8).
 *
 * WHY A TOKEN RATHER THAN THE SESSION ID
 * --------------------------------------
 * Laravel regenerates the session ID on sign-in, and may regenerate it again
 * later. Keying these records on the raw session ID therefore means a stored
 * row can stop matching the live session — and when it does, the failure is
 * silent and total: last_activity_at never updates, so the idle timeout never
 * fires; revocation never matches, so "sign out everywhere" does nothing; and
 * sign-out leaves the row active forever.
 *
 * So Aziv AI writes its OWN identifier into the session payload. Session data
 * survives ID regeneration, so the token is stable for the life of the
 * session, and every lookup keys on it.
 */
class SessionGuard
{
    public const TOKEN_KEY = 'aziv.session_token';

    /** Stable per-session identifier, created on first use. */
    public function token(Request $request): string
    {
        $session = $request->session();

        if (! $session->has(self::TOKEN_KEY)) {
            $session->put(self::TOKEN_KEY, (string) Str::uuid());
        }

        return (string) $session->get(self::TOKEN_KEY);
    }

    public function record(User $user, Request $request): UserSession
    {
        $session = UserSession::updateOrCreate(
            ['user_id' => $user->getKey(), 'session_id' => $this->token($request)],
            [
                'ip' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
                'last_activity_at' => now(),
                'revoked_at' => null,
                'revoked_reason' => null,
            ],
        );

        $this->enforceConcurrencyLimit($user, $session);

        return $session;
    }

    public function touch(User $user, string $token): void
    {
        UserSession::where('user_id', $user->getKey())
            ->where('session_id', $token)
            ->whereNull('revoked_at')
            ->update(['last_activity_at' => now()]);
    }

    public function revokeCurrent(User $user, string $token, string $reason = 'signed_out'): void
    {
        UserSession::where('user_id', $user->getKey())
            ->where('session_id', $token)
            ->update(['revoked_at' => now(), 'revoked_reason' => $reason]);
    }

    /** Oldest sessions are signed out first when the limit is exceeded. */
    private function enforceConcurrencyLimit(User $user, UserSession $current): void
    {
        $limit = (int) settings('auth.max_concurrent_sessions');

        if ($limit <= 0) {
            return;
        }

        $active = UserSession::where('user_id', $user->getKey())
            ->whereNull('revoked_at')
            ->orderByDesc('last_activity_at')
            ->get();

        if ($active->count() <= $limit) {
            return;
        }

        $active->slice($limit)
            ->reject(fn (UserSession $s) => $s->is($current))
            ->each(fn (UserSession $s) => $s->update([
                'revoked_at' => now(),
                'revoked_reason' => 'concurrency_limit',
            ]));
    }

    public function isIdleExpired(User $user, string $token): bool
    {
        $minutes = (int) settings('auth.idle_timeout_minutes');

        if ($minutes <= 0) {
            return false;
        }

        $session = UserSession::where('user_id', $user->getKey())
            ->where('session_id', $token)
            ->first();

        if (! $session || ! $session->last_activity_at) {
            return false;
        }

        return $session->last_activity_at->addMinutes($minutes)->isPast();
    }

    public function isRevoked(User $user, string $token): bool
    {
        return UserSession::where('user_id', $user->getKey())
            ->where('session_id', $token)
            ->whereNotNull('revoked_at')
            ->exists();
    }

    public function revokeAll(User $user, string $reason, ?string $exceptToken = null): int
    {
        return UserSession::where('user_id', $user->getKey())
            ->whereNull('revoked_at')
            ->when($exceptToken, fn ($q) => $q->where('session_id', '!=', $exceptToken))
            ->update(['revoked_at' => now(), 'revoked_reason' => $reason]);
    }
}
