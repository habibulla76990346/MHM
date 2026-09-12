<?php

namespace App\Domains\Payments\Services;

use App\Domains\Payments\Models\Payment;
use App\Domains\Payments\Models\PaymentGatewayRecord;
use App\Domains\Payments\Models\PaymentTransaction;
use App\Domains\Payments\Models\PaymentWebhookEvent;
use App\Domains\Security\Services\ActivityLogger;
use Illuminate\Database\QueryException;

/**
 * Webhook handling (Addendum D §4.2 and §4.4).
 *
 * TWO RULES, both easy to get wrong and both expensive:
 *
 *  1. VERIFY AGAINST THE RAW BODY. The exact bytes received, before any JSON
 *     parsing — re-encoding changes whitespace and key order, the signature
 *     then fails, and the tempting fix is to stop verifying. The controller
 *     hands the raw string straight through for this reason.
 *
 *  2. CLAIM THE EVENT BY INSERTING IT. The unique index on (gateway, event id)
 *     is the replay guard, and inserting the row is how a delivery claims the
 *     work. A retry loses the race, does nothing, and still answers 200 —
 *     because a gateway that is not acknowledged retries for ever.
 *
 * A rejected signature is RECORDED and ALERTED, not silently dropped: a run of
 * failures is either a misconfigured secret or somebody probing the endpoint,
 * and an owner needs to see both.
 */
class WebhookProcessor
{
    public function __construct(
        private readonly PaymentGatewayRegistry $registry,
        private readonly CheckoutService $checkout,
    ) {}

    /**
     * @param  array<string, mixed>  $headers
     * @return array{status: int, body: array<string, mixed>}
     */
    public function handle(string $gatewayKey, string $rawBody, array $headers): array
    {
        $gateway = PaymentGatewayRecord::where('key', $gatewayKey)->first();
        $adapter = $this->registry->for($gateway);

        if (! $gateway || ! $adapter) {
            // 404 rather than 401: an endpoint for a gateway that is not
            // configured has nothing to authenticate against.
            return ['status' => 404, 'body' => ['error' => 'unknown gateway']];
        }

        $verification = $adapter->verifyWebhook($rawBody, $headers);

        if (! $verification->valid) {
            $this->recordRejection($gateway, $rawBody, $verification->reason);

            return ['status' => 401, 'body' => ['error' => 'signature rejected']];
        }

        $eventId = (string) ($verification->eventId ?: sha1($rawBody));

        try {
            $event = PaymentWebhookEvent::create([
                'gateway_id' => $gateway->getKey(),
                'event_id' => $eventId,
                'event_type' => $verification->eventType,
                'raw_payload' => $verification->payload,
                'signature_valid' => true,
                'received_at' => now(),
                'attempts' => 1,
            ]);
        } catch (QueryException $e) {
            if (! $this->isDuplicate($e)) {
                throw $e;
            }

            // Already seen. Count the retry and acknowledge — doing the work
            // again is exactly what must not happen.
            PaymentWebhookEvent::where('gateway_id', $gateway->getKey())
                ->where('event_id', $eventId)
                ->increment('attempts');

            return ['status' => 200, 'body' => ['status' => 'already processed']];
        }

        $payment = $this->paymentFor($verification->gatewayPaymentId, $verification->reference, $verification->payload);

        if (! $payment) {
            $event->forceFill([
                'processed_at' => now(),
                'result' => 'no matching payment',
            ])->save();

            // Still a 200: the signature was valid, so this is our problem to
            // investigate, not something the gateway should keep retrying.
            return ['status' => 200, 'body' => ['status' => 'no matching payment']];
        }

        if ($verification->gatewayPaymentId) {
            $payment->forceFill(['gateway_payment_id' => $verification->gatewayPaymentId])->save();
        }

        $settled = $this->checkout->settle($payment, PaymentTransaction::WEBHOOK);

        $event->forceFill([
            'processed_at' => now(),
            'result' => $settled->status,
        ])->save();

        return ['status' => 200, 'body' => ['status' => 'ok']];
    }

    /**
     * Match a notification to a payment.
     *
     * By the gateway's payment id, then by the order reference, then by the
     * metadata we asked the gateway to echo back. Three ways because gateways
     * differ in which of them a given event type carries.
     *
     * @param  array<string, mixed>  $payload
     */
    private function paymentFor(?string $gatewayPaymentId, ?string $reference, array $payload): ?Payment
    {
        if (filled($gatewayPaymentId)) {
            $payment = Payment::where('gateway_payment_id', $gatewayPaymentId)->first();

            if ($payment) {
                return $payment;
            }
        }

        if (filled($reference)) {
            $payment = Payment::where('gateway_order_id', $reference)->first();

            if ($payment) {
                return $payment;
            }
        }

        $uuid = data_get($payload, 'payload.payment.entity.notes.payment_uuid')
            ?? data_get($payload, 'notes.payment_uuid');

        return filled($uuid) ? Payment::where('uuid', $uuid)->first() : null;
    }

    /**
     * A rejected delivery is evidence, not noise.
     *
     * Recorded with the body so an owner can see what arrived, and alerted so
     * a wrong secret is noticed in minutes rather than at the end of the month
     * when the payments did not arrive.
     */
    private function recordRejection(PaymentGatewayRecord $gateway, string $rawBody, ?string $reason): void
    {
        $event = PaymentWebhookEvent::create([
            'gateway_id' => $gateway->getKey(),
            // Unique per delivery, so repeated probes are all recorded rather
            // than collapsing into one row.
            'event_id' => 'rejected:'.sha1($rawBody.microtime(true)),
            'event_type' => 'signature.rejected',
            'raw_payload' => ['reason' => $reason],
            'signature_valid' => false,
            'received_at' => now(),
            'processed_at' => now(),
            'result' => $reason,
        ]);

        app(ActivityLogger::class)->log('payments.webhook_rejected', $gateway, null, [
            'gateway' => $gateway->name,
            'reason' => $reason,
            'recent_rejections' => $this->recentRejections($gateway),
            'event' => $event->uuid,
        ]);
    }

    public function recentRejections(PaymentGatewayRecord $gateway, int $minutes = 60): int
    {
        return PaymentWebhookEvent::where('gateway_id', $gateway->getKey())
            ->where('signature_valid', false)
            ->where('received_at', '>=', now()->subMinutes($minutes))
            ->count();
    }

    private function isDuplicate(QueryException $e): bool
    {
        return $e->getCode() === '23000' || str_contains($e->getMessage(), 'Duplicate entry');
    }
}
