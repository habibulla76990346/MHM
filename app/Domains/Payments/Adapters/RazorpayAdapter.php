<?php

namespace App\Domains\Payments\Adapters;

use App\Domains\Payments\Contracts\SupportsInternational;
use App\Domains\Payments\Contracts\SupportsOneTimePayment;
use App\Domains\Payments\Contracts\SupportsPartialRefunds;
use App\Domains\Payments\Contracts\SupportsRefunds;
use App\Domains\Payments\DTO\CheckoutRequest;
use App\Domains\Payments\DTO\CheckoutSession;
use App\Domains\Payments\DTO\GatewayTestResult;
use App\Domains\Payments\DTO\RefundRequest;
use App\Domains\Payments\DTO\RefundResult;
use App\Domains\Payments\DTO\TransactionStatus;
use App\Domains\Payments\DTO\WebhookVerification;
use App\Domains\Payments\Exceptions\GatewayFailed;
use App\Domains\Payments\Support\Capability;
use App\Domains\Payments\Support\CheckoutMode;
use Illuminate\Http\Client\PendingRequest;

/**
 * The first gateway (owner decision D-01, as amended by Addendum D).
 *
 * WHAT IT DECLARES AND WHY. One-time payments, refunds and partial refunds are
 * implemented and proven here. Native recurring billing and mandates are NOT
 * declared: they exist on the platform, but each carries its own registration
 * flow and RBI e-mandate rules, and declaring a capability this adapter has
 * not implemented would let the selector route a subscription somewhere that
 * cannot renew it — the exact failure Addendum D §3 exists to prevent. A plan
 * sold through this adapter renews by invoice-and-pay until that work is done,
 * which is a deliberate choice with different customer messaging, not an
 * accident.
 *
 * International acceptance is declared because the API supports it, but
 * whether a given merchant account may actually take a non-INR payment is a
 * fact about the owner's agreement — so the currencies allowed are read from
 * the gateway row the owner configured, never assumed here.
 *
 * NO SDK. One HTTP client, the same as every AI adapter, so there is no
 * dependency to update and nothing new to install on a shared host.
 */
class RazorpayAdapter extends BaseGatewayAdapter implements SupportsInternational, SupportsOneTimePayment, SupportsPartialRefunds, SupportsRefunds
{
    public const KEY = 'razorpay';

    public const DEFAULT_BASE_URL = 'https://api.razorpay.com/v1';

    public function key(): string
    {
        return self::KEY;
    }

    public function capabilities(): array
    {
        return [
            Capability::ONE_TIME,
            Capability::REFUNDS,
            Capability::PARTIAL_REFUNDS,
            Capability::INTERNATIONAL,
        ];
    }

    public function checkoutMode(): string
    {
        // Its JavaScript opens over the merchant's own page, so the customer
        // never leaves Aziv AI's branding.
        return CheckoutMode::SDK_MODAL;
    }

    protected function client(): PendingRequest
    {
        $credential = $this->credential();

        // Basic auth: the key identifies the account, the secret authorises.
        // Read once, here, and never anywhere else in this class.
        return $this->baseClient()->withBasicAuth(
            (string) $credential->secretValue('key_id'),
            (string) $credential->secretValue('key_secret'),
        );
    }

    // -- taking a payment -----------------------------------------------------

    public function createCheckout(CheckoutRequest $request): CheckoutSession
    {
        [$response, $latency] = $this->send(fn (PendingRequest $http) => $http->post($this->url('orders'), [
            // Integer minor units. Sending a float here is how an amount ends
            // up off by a rounding error in the customer's favour or the
            // owner's.
            'amount' => $this->toMinorUnits($request->amount, $request->currency),
            'currency' => strtoupper($request->currency),
            'receipt' => $request->reference,
            // Our own ids come back on every webhook, which is what lets a
            // notification be matched to a payment without trusting the
            // browser.
            'notes' => $request->metadata,
        ]));

        $body = $response->json();
        $orderId = (string) data_get($body, 'id', '');

        if ($orderId === '') {
            throw new GatewayFailed(GatewayFailed::GATEWAY_ERROR, $response->status(), $latency);
        }

        return new CheckoutSession(
            gatewayReference: $orderId,
            mode: $this->checkoutMode(),
            redirectUrl: null,
            // ONLY values that are safe in a page. The publishable key
            // identifies the merchant to the gateway's own script and
            // authorises nothing; the secret is not here and cannot be.
            publicConfig: [
                'key' => $this->credential()->publishable_key
                    ?? $this->credential()->secretValue('key_id'),
                'order_id' => $orderId,
                'amount' => (int) data_get($body, 'amount', 0),
                'currency' => (string) data_get($body, 'currency', $request->currency),
                'name' => (string) settings('branding.app_name'),
                'description' => $request->description,
                'prefill' => array_filter([
                    'email' => $request->customerEmail,
                    'name' => $request->customerName,
                    'contact' => $request->customerPhone,
                ]),
            ],
            orderId: $orderId,
            latencyMs: $latency,
        );
    }

    /**
     * Check the values the browser handed back.
     *
     * A valid signature proves the browser did not tamper with them. It does
     * NOT prove the money moved — that is `fetchTransaction()`, always.
     */
    public function verifyReturn(array $payload, array $headers): WebhookVerification
    {
        $orderId = (string) ($payload['razorpay_order_id'] ?? '');
        $paymentId = (string) ($payload['razorpay_payment_id'] ?? '');
        $signature = (string) ($payload['razorpay_signature'] ?? '');

        if ($orderId === '' || $paymentId === '' || $signature === '') {
            return WebhookVerification::rejected('The payment result was incomplete.');
        }

        $secret = (string) $this->credential()->secretValue('key_secret');
        $expected = hash_hmac('sha256', $orderId.'|'.$paymentId, $secret);

        if (! $this->signatureMatches($expected, $signature)) {
            return WebhookVerification::rejected('The payment result did not match its signature.');
        }

        return new WebhookVerification(
            valid: true,
            eventId: 'return:'.$paymentId,
            eventType: 'payment.returned',
            gatewayPaymentId: $paymentId,
            reference: $orderId,
            payload: $this->scrub($payload),
        );
    }

    /**
     * Verify a webhook against the RAW body.
     *
     * The exact bytes received, before any parsing: json_decode and re-encode
     * changes whitespace and key order, the signature then fails, and the
     * tempting "fix" is to stop verifying.
     */
    public function verifyWebhook(string $rawBody, array $headers): WebhookVerification
    {
        $signature = $this->header($headers, 'x-razorpay-signature');
        $secret = (string) $this->credential()->signingSecret();

        if ($secret === '') {
            return WebhookVerification::rejected('No webhook secret is configured for this gateway.');
        }

        if ($signature === null) {
            return WebhookVerification::rejected('The request carried no signature.');
        }

        if (! $this->signatureMatches(hash_hmac('sha256', $rawBody, $secret), $signature)) {
            return WebhookVerification::rejected('The signature did not match the body.');
        }

        $payload = json_decode($rawBody, true) ?: [];
        $entity = data_get($payload, 'payload.payment.entity', []);

        return new WebhookVerification(
            valid: true,
            // The gateway's own delivery id where it sends one; otherwise the
            // payment id, which is stable per event type and still makes a
            // replay a no-op.
            eventId: $this->header($headers, 'x-razorpay-event-id')
                ?? (string) data_get($payload, 'event', 'event').':'.data_get($entity, 'id', 'unknown'),
            eventType: (string) data_get($payload, 'event', ''),
            gatewayPaymentId: (string) data_get($entity, 'id', ''),
            reference: (string) data_get($entity, 'order_id', ''),
            payload: $this->scrub($payload),
        );
    }

    public function fetchTransaction(string $gatewayReference): TransactionStatus
    {
        [$response] = $this->send(
            fn (PendingRequest $http) => $http->get($this->url('payments/'.urlencode($gatewayReference))),
        );

        $body = (array) $response->json();
        $currency = (string) data_get($body, 'currency', 'INR');

        return new TransactionStatus(
            status: $this->normaliseStatus((string) data_get($body, 'status', '')),
            gatewayPaymentId: (string) data_get($body, 'id', $gatewayReference),
            amount: $this->fromMinorUnits(data_get($body, 'amount', 0), $currency),
            currency: $currency,
            // What the account is actually credited, which for a cross-border
            // payment is neither the presented amount nor its currency.
            settlementAmount: data_get($body, 'base_amount') !== null
                ? $this->fromMinorUnits(data_get($body, 'base_amount'), 'INR')
                : null,
            settlementCurrency: data_get($body, 'base_amount') !== null ? 'INR' : null,
            failureCode: data_get($body, 'error_code'),
            // A code and a step, never the gateway's prose.
            failureReason: data_get($body, 'error_step'),
            method: data_get($body, 'method'),
            raw: $this->scrub($body),
        );
    }

    public function refund(RefundRequest $request): RefundResult
    {
        [$response] = $this->send(fn (PendingRequest $http) => $http->post(
            $this->url('payments/'.urlencode($request->gatewayPaymentId).'/refund'),
            [
                'amount' => $this->toMinorUnits($request->amount, $request->currency),
                'notes' => ['reason' => $request->reason, 'reference' => $request->reference],
            ],
        ));

        $body = (array) $response->json();

        return new RefundResult(
            status: match ((string) data_get($body, 'status', '')) {
                'processed' => RefundResult::COMPLETED,
                'failed' => RefundResult::FAILED,
                default => RefundResult::PENDING,
            },
            gatewayRefundId: (string) data_get($body, 'id', ''),
            amount: $this->fromMinorUnits(data_get($body, 'amount', 0), $request->currency),
        );
    }

    /**
     * A minimal authenticated call.
     *
     * Deliberately a READ that takes no money and creates nothing: an owner
     * clicking "test" in the panel must never produce a real order.
     */
    public function testConnection(): GatewayTestResult
    {
        try {
            [, $latency] = $this->send(
                fn (PendingRequest $http) => $http->get($this->url('payments'), ['count' => 1]),
            );

            return GatewayTestResult::pass($latency);
        } catch (GatewayFailed $e) {
            return GatewayTestResult::fail($e->errorClass, $e->action(), $e->latencyMs);
        }
    }

    // -- internals ------------------------------------------------------------

    private function normaliseStatus(string $status): string
    {
        return match ($status) {
            // "captured" is the only one that means the money is ours.
            // "authorized" is a hold that expires — treating it as paid is how
            // a customer gets credits for money that is later released.
            'captured' => TransactionStatus::PAID,
            'refunded' => TransactionStatus::REFUNDED,
            'failed' => TransactionStatus::FAILED,
            'created', 'authorized' => TransactionStatus::PENDING,
            default => TransactionStatus::UNKNOWN,
        };
    }

    /** @param array<string, mixed> $headers */
    private function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp((string) $key, $name) === 0) {
                return is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
            }
        }

        return null;
    }
}
