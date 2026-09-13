<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The handful of HTTP headers that cost nothing and close real attacks.
 *
 * WHY THIS LIST IS SHORT. A security header that has to be loosened later is
 * worse than one that was never sent: the loosening happens under pressure,
 * usually by widening it further than the original problem required. So this
 * carries only headers whose correct value is the same today as it will be
 * after voice and image generation ship.
 *
 * WHAT IS DELIBERATELY NOT HERE
 *
 *  - `Content-Security-Policy`. Not an oversight and not laziness. A policy
 *    tight enough to be worth having would have to name the hosts that serve
 *    each payment gateway's checkout script — and a gateway's name may not
 *    appear outside `app/Domains/Payments/Adapters/` (Addendum D), which the
 *    tokeniser scan enforces. The adapter already declares its script through
 *    `checkoutSdkUrl()`, so a derived policy is possible and is recorded as
 *    future work rather than guessed at now. A wrong CSP does not fail loudly;
 *    it silently stops the Pay button from working.
 *  - `Permissions-Policy`. The obvious entries to disable are microphone and
 *    camera, and both are on the roadmap (§18 voice, §16 images). Shipping a
 *    denial that a later phase must reverse teaches whoever reverses it that
 *    these headers are obstacles.
 *  - `X-XSS-Protection`. Retired by every current browser; the last engines to
 *    honour it could be tricked into introducing a vulnerability with it.
 *
 * HSTS IS THE ONE WITH TEETH, and it is sent only over TLS. Announcing it on a
 * plain HTTP response is ignored, and sending it from a development machine
 * would pin `localhost` to HTTPS in the developer's own browser — which is
 * both confusing and difficult to undo.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        // Stops a browser from deciding that an uploaded file is really a
        // script. The upload pipeline validates types already; this is the
        // second lock on the same door.
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // Framing the admin panel or the chat on somebody else's page is how
        // a click gets stolen.
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');

        // A referrer carries the full path. A signed renewal link in the
        // address bar must not be handed to whatever the customer clicks next.
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Legacy Flash/PDF cross-domain policy files. Nothing serves them here
        // and this says so.
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');

        $maxAge = (int) config('aziv.security.hsts_max_age');

        if ($maxAge > 0 && $request->isSecure()) {
            $value = 'max-age='.$maxAge;

            if (config('aziv.security.hsts_include_subdomains')) {
                $value .= '; includeSubDomains';
            }

            $response->headers->set('Strict-Transport-Security', $value);
        }

        return $response;
    }
}
