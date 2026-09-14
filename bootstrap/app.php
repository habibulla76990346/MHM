<?php

use App\Domains\Diagnostics\Console\DiagnoseCommand;
use App\Http\Middleware\EnforceSessionPolicy;
use App\Http\Middleware\EnsureNotInMaintenance;
use App\Http\Middleware\RequireMfaChallenge;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

/**
 * Which proxies may speak for the client.
 *
 * A helper rather than an inline expression so the reasoning has somewhere to
 * live, and so the deployment documentation can point at one name.
 *
 * @return array<int, string>|string|null
 */
$proxies = static function (): array|string|null {
    $configured = trim((string) env('TRUSTED_PROXIES', ''));

    if ($configured === '') {
        // Nothing configured: trust nothing, which is correct for a server
        // with no proxy in front of it and is what a developer machine is.
        return null;
    }

    // '*' is the honest answer behind Cloudflare or a managed load balancer,
    // whose egress addresses change without notice.
    return $configured === '*' ? '*' : array_map('trim', explode(',', $configured));
};

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // A separate file so it is obvious what it contains, and so an owner
        // who will never need it can see exactly what to delete.
        then: function () {
            require __DIR__.'/../routes/install.php';
        },
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        DiagnoseCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware) use ($proxies): void {
        /**
         * TLS almost always terminates somewhere else — Cloudflare, a load
         * balancer, or nginx in front of PHP — and without this Laravel sees
         * the plain HTTP hop behind it.
         *
         * WHAT THAT BREAKS IS NOT OBVIOUS. Every signed URL in the platform is
         * an HMAC over the FULL address including the scheme. The renewal
         * payment link, the password reset and the email verification link are
         * all generated as `https://` by the scheduler or the queue, and
         * validated against what the request appears to be. Untrusted, that is
         * `http://`, the signatures do not match, and the customer gets a bare
         * 403 on a link that is perfectly valid. It cannot happen on a
         * developer's machine, so the first person to find it is a paying
         * customer trying to pay an invoice.
         *
         * The proxy list is CONFIGURATION, not a constant: `*` is right behind
         * Cloudflare or a load balancer whose address is not fixed, and a
         * named range is better where one exists. It is deliberately not the
         * default — trusting every proxy on a server that has none in front of
         * it would let a client set its own forwarded headers.
         */
        $middleware->trustProxies(
            at: $proxies(),
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_AWS_ELB,
        );

        // GLOBAL, not web-only. A webhook, a health probe and an SSE stream
        // are responses too, and `nosniff` on a JSON error page is as much
        // worth having as on a rendered one.
        $middleware->append(SecurityHeaders::class);

        $middleware->web(append: [
            EnsureNotInMaintenance::class,
            EnforceSessionPolicy::class,
            // After the session policy, so a revoked session is refused
            // before it is asked for a code — and before anything else, so no
            // screen renders for somebody who has not answered the challenge.
            RequireMfaChallenge::class,
        ]);

        // A payment gateway has no session and no CSRF token. What
        // authenticates its webhook is the signature over the raw body, which
        // is verified for every delivery and is not optional — see
        // WebhookProcessor. Excluding the path here is what lets that check be
        // the only one, rather than a second-best after a token that could
        // never be present.
        $middleware->validateCsrfTokens(except: [
            'webhooks/payments/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
