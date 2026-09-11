<?php

namespace App\Http\Controllers\Auth;

use App\Domains\Identity\Services\SessionGuard;
use App\Domains\Security\Services\ActivityLogger;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class AuthenticatedSessionController extends Controller
{
    public function create(): Response
    {
        return response()->view('auth.login');
    }

    public function store(Request $request, SessionGuard $sessions, ActivityLogger $log): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $throttleKey = strtolower($credentials['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw ValidationException::withMessages([
                'email' => __('Too many sign-in attempts. Try again in :seconds seconds.', [
                    'seconds' => RateLimiter::availableIn($throttleKey),
                ]),
            ]);
        }

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($throttleKey, 60);

            $log->log('auth.login_failed', null, null, null, ['email' => $credentials['email']]);

            // Deliberately does not reveal whether the address exists.
            throw ValidationException::withMessages([
                'email' => __('Those details do not match our records.'),
            ]);
        }

        $user = Auth::user();

        // A suspended account must not obtain a session, even with the right
        // password. Checked AFTER attempt so the message cannot be used to
        // enumerate which addresses are suspended.
        if ($user->isSuspended()) {
            Auth::logout();
            $request->session()->invalidate();

            $log->log('auth.login_blocked_suspended', $user);

            throw ValidationException::withMessages([
                'email' => __('This account has been suspended. Contact support if you think this is a mistake.'),
            ]);
        }

        RateLimiter::clear($throttleKey);

        $request->session()->regenerate();
        $sessions->record($user, $request);

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        $log->log('auth.login', $user);

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request, SessionGuard $sessions, ActivityLogger $log): RedirectResponse
    {
        $user = Auth::user();

        if ($user) {
            $sessions->revokeCurrent($user, $sessions->token($request));
            $log->log('auth.logout', $user);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
