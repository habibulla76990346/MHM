<?php

namespace App\Http\Controllers\Auth;

use App\Domains\Identity\Services\MfaService;
use App\Domains\Security\Services\ActivityLogger;
use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireMfaChallenge;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Setting up and answering the second factor (§23).
 *
 * THE CHALLENGE IS RATE LIMITED PER ACCOUNT, not per IP. A six-digit code has
 * a million possibilities and a thirty-second life; without a limit that is
 * brute-forceable in the window, and an attacker who already has the password
 * has as many IP addresses as they like.
 *
 * NOTHING HERE ECHOES A SECRET BACK except once, deliberately: the recovery
 * codes, on the single page immediately after enrolment. They cannot be shown
 * again, because what is stored is a hash of each — which is the property that
 * makes them safe to store at all.
 */
class MfaController extends Controller
{
    /** Attempts before the account is locked out of the challenge. */
    private const MAX_ATTEMPTS = 5;

    public function __construct(private readonly MfaService $mfa) {}

    // -- enrolment ---------------------------------------------------------------

    public function setup(Request $request): View
    {
        $user = $request->user();

        // A fresh secret each time the page is opened while unconfirmed: an
        // abandoned enrolment must not leave a usable secret lying in the
        // database.
        $secret = $this->mfa->isEnabledFor($user)
            ? null
            : $this->mfa->beginEnrolment($user);

        return view('auth.mfa-setup', [
            'enabled' => $this->mfa->isEnabledFor($user),
            'required' => $this->mfa->isRequiredFor($user),
            'secret' => $secret,
            'uri' => $secret ? $this->mfa->provisioningUri($user, $secret) : null,
            'remaining' => $this->mfa->isEnabledFor($user) ? $this->mfa->remainingRecoveryCodes($user) : 0,
        ]);
    }

    public function confirm(Request $request): RedirectResponse|View
    {
        $request->validate(['code' => ['required', 'string', 'max:16']]);

        $codes = $this->mfa->confirmEnrolment($request->user(), (string) $request->input('code'));

        if ($codes === null) {
            throw ValidationException::withMessages([
                'code' => __('That code did not match. Check your phone\'s clock is correct and try the next one.'),
            ]);
        }

        // Passing enrolment counts as passing the challenge for this session:
        // they just proved possession of the device.
        $request->session()->put(RequireMfaChallenge::PASSED, now()->toIso8601String());

        // The ONE time these are readable. What is stored is a hash of each.
        return view('auth.mfa-recovery-codes', ['codes' => $codes, 'regenerated' => false]);
    }

    public function regenerate(Request $request): View
    {
        abort_unless($this->mfa->isEnabledFor($request->user()), 404);

        return view('auth.mfa-recovery-codes', [
            'codes' => $this->mfa->regenerateRecoveryCodes($request->user()),
            'regenerated' => true,
        ]);
    }

    /**
     * Turning it off requires the password.
     *
     * Because the threat is a session somebody else is holding: without this,
     * a borrowed laptop removes the second factor in one click, which is the
     * exact scenario it was added for.
     */
    public function disable(Request $request, ActivityLogger $log): RedirectResponse
    {
        $request->validate(['password' => ['required', 'string']]);

        if (! Hash::check((string) $request->input('password'), (string) $request->user()->password)) {
            throw ValidationException::withMessages(['password' => __('That is not your password.')]);
        }

        if ($this->mfa->isRequiredFor($request->user())) {
            // The owner has made it mandatory for administrators. Allowing it
            // off here would make the setting decorative.
            throw ValidationException::withMessages([
                'password' => __('Your administrator has made a second factor mandatory, so it cannot be switched off.'),
            ]);
        }

        $this->mfa->disable($request->user(), $request->user());

        return redirect()->route('account')->with('status', __('Two-factor authentication is off.'));
    }

    // -- the challenge --------------------------------------------------------------

    public function challenge(Request $request): View|RedirectResponse
    {
        if (! $this->mfa->isEnabledFor($request->user())) {
            return redirect()->route('dashboard');
        }

        return view('auth.mfa-challenge');
    }

    public function verify(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:16']]);

        $user = $request->user();
        // Per ACCOUNT, not per IP: an attacker with the password has as many
        // addresses as they like, and the thing being protected is the account.
        $key = 'mfa:'.$user->getKey();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'code' => __('Too many attempts. Try again in :seconds seconds.', [
                    'seconds' => RateLimiter::availableIn($key),
                ]),
            ]);
        }

        if (! $this->mfa->verify($user, (string) $request->input('code'))) {
            RateLimiter::hit($key, 300);

            throw ValidationException::withMessages([
                'code' => __('That code is not right.'),
            ]);
        }

        RateLimiter::clear($key);

        // Regenerated on the second factor for the same reason it is
        // regenerated on login: a session id an attacker already holds must
        // not become an authenticated one.
        $request->session()->regenerate();
        $request->session()->put(RequireMfaChallenge::PASSED, now()->toIso8601String());

        return redirect()->intended(route('dashboard'));
    }
}
