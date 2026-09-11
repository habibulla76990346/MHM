<?php

namespace App\Http\Middleware;

use App\Domains\Identity\Services\SessionGuard;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces, on every authenticated request:
 *   - the session has not been revoked elsewhere (concurrency limit, sign-out
 *     everywhere, password reset, admin action)
 *   - the idle timeout has not elapsed
 *   - the account is still allowed to be signed in
 *
 * Checking on each request rather than only at login is the point: an
 * administrator suspending an account must end its active sessions now, not
 * whenever the user next signs in.
 */
class EnforceSessionPolicy
{
    public function __construct(private readonly SessionGuard $sessions)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if (! $user) {
            return $next($request);
        }

        // Our own stable token, not Laravel's session id — see SessionGuard.
        $token = $this->sessions->token($request);

        if ($user->isSuspended()) {
            return $this->signOut($request, __('This account has been suspended.'));
        }

        if ($this->sessions->isRevoked($user, $token)) {
            return $this->signOut($request, __('You were signed out. Please sign in again.'));
        }

        if ($this->sessions->isIdleExpired($user, $token)) {
            $this->sessions->revokeAll($user, 'idle_timeout', null);

            return $this->signOut($request, __('You were signed out after a period of inactivity.'));
        }

        $this->sessions->touch($user, $token);

        return $next($request);
    }

    private function signOut(Request $request, string $message): Response
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', $message);
    }
}
