<?php

namespace App\Http\Controllers\Webhooks;

use App\Domains\Payments\Services\WebhookProcessor;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * One endpoint per gateway, resolved by key (Addendum D §8).
 *
 * The controller knows NOTHING about any gateway. It hands the raw body and
 * the headers to the processor, which asks the adapter to verify them.
 *
 * THE RAW BODY MATTERS. `$request->getContent()` returns the exact bytes that
 * arrived; `$request->all()` would parse and re-encode them, changing
 * whitespace and key order, and every signature would then fail. The usual
 * "fix" for that is to stop verifying, which is how an endpoint ends up
 * granting credits to anyone who can find its URL.
 *
 * CSRF is excluded for this path in bootstrap/app.php — a gateway has no
 * session and no token. The signature is what authenticates it, and it is
 * mandatory.
 */
class PaymentWebhookController extends Controller
{
    public function __invoke(Request $request, string $gateway, WebhookProcessor $processor): JsonResponse
    {
        $result = $processor->handle(
            $gateway,
            $request->getContent(),
            $request->headers->all(),
        );

        return response()->json($result['body'], $result['status']);
    }
}
