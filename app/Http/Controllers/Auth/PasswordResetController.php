<?php

namespace App\Http\Controllers\Auth;

use App\Domains\Identity\Services\PasswordPolicy;
use App\Domains\Identity\Services\SessionGuard;
use App\Domains\Security\Services\ActivityLogger;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class PasswordResetController extends Controller
{
    public function requestForm(): Response
    {
        return response()->view('auth.forgot-password');
    }

    public function sendLink(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        Password::sendResetLink($request->only('email'));

        // Always the same response, whether or not the address exists —
        // otherwise this endpoint becomes an account-enumeration oracle.
        return back()->with('status', __('If that address has an account, a reset link is on its way.'));
    }

    public function resetForm(Request $request, string $token): Response
    {
        return response()->view('auth.reset-password', [
            'token' => $token,
            'email' => $request->string('email')->toString(),
            'passwordHint' => PasswordPolicy::describe(),
        ]);
    }

    public function reset(Request $request, SessionGuard $sessions, ActivityLogger $log): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordPolicy::rule()],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) use ($sessions, $log) {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                // A password reset invalidates every existing session: if the
                // reset was prompted by a compromise, leaving old sessions
                // alive defeats the point.
                $sessions->revokeAll($user, 'password_reset');

                $log->log('auth.password_reset', $user);

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PasswordReset) {
            throw ValidationException::withMessages(['email' => [__($status)]]);
        }

        return redirect()->route('login')->with('status', __('Your password has been changed. Please sign in.'));
    }
}
