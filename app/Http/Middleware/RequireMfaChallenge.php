<?php

namespace App\Http\Middleware;

use App\Domains\Identity\Services\MfaService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A signed-in administrator who has not answered the second factor gets one
 * page: the challenge.
 *
 * THE SESSION FLAG IS THE GATE, and it is set only by the challenge
 * controller. Authentication and the second factor are separate events, so a
 * stolen session cookie from before the challenge is worth nothing.
 *
 * IT ALSO CATCHES SOMEBODY WHO HAS NOT SET IT UP YET. When the owner switches
 * enforcement on, every administrator's next request lands on the enrolment
 * page rather than on a 403 they cannot act on — the whole point of the
 * setting is to get everybody enrolled, not to lock them out.
 *
 * A CUSTOMER IS NEVER ASKED. Enforcement covers people who can reach the Admin
 * Panel; a customer forced through TOTP to read their own invoices is a
 * customer who leaves, and their account cannot change a price or read a
 * credential.
 */
class RequireMfaChallenge
{
    public const PASSED = 'mfa.passed_at';

    public function __construct(private readonly MfaService $mfa) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        // Never gets in the way of answering the challenge, or of leaving.
        if ($request->routeIs('mfa.*') || $request->routeIs('logout') || $request->routeIs('install.*')) {
            return $next($request);
        }

        if ($this->mfa->isEnabledFor($user)) {
            return $request->session()->has(self::PASSED)
                ? $next($request)
                : redirect()->guest(route('mfa.challenge'));
        }

        if ($this->mfa->isRequiredFor($user)) {
            // Required and not set up. Somewhere to go, not a wall.
            return redirect()->route('mfa.setup');
        }

        return $next($request);
    }
}
