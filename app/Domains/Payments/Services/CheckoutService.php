<?php

namespace App\Domains\Payments\Services;

use App\Domains\AI\Models\ExchangeRate;
use App\Domains\Billing\Models\Invoice;
use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Models\Subscription;
use App\Domains\Billing\Services\InvoiceService;
use App\Domains\Billing\Services\SubscriptionService;
use App\Domains\Payments\DTO\CheckoutRequest;
use App\Domains\Payments\DTO\CheckoutSession;
use App\Domains\Payments\DTO\TransactionStatus;
use App\Domains\Payments\Exceptions\GatewayFailed;
use App\Domains\Payments\Models\Payment;
use App\Domains\Payments\Models\PaymentTransaction;
use App\Domains\Payments\Support\GatewayDecision;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Starting a payment, and settling it exactly once (Addendum D §4).
 *
 * THE WHOLE DESIGN IS ONE IDEMPOTENT HANDLER. Three paths lead to money having
 * moved — the customer's browser coming back, a webhook, and the scheduled
 * sweep — and all three converge on `settle()`. None of them is trusted on its
 * own: each one only says "go and look", and `settle()` asks the gateway.
 *
 *   Return callback   can be replayed, edited, or never arrive
 *   Webhook           authentic once verified, but delivered more than once
 *   Sweep             the safety net for the customer who closed the tab
 *
 * Without the sweep, money is taken and nothing is delivered. Without
 * idempotency, one payment grants two months of credits.
 */
class CheckoutService
{
    public function __construct(
        private readonly PaymentGatewayRegistry $registry,
        private readonly GatewaySelector $selector,
        private readonly SubscriptionService $subscriptions,
        private readonly InvoiceService $invoices,
    ) {}

    /**
     * Begin a purchase.
     *
     * @throws RuntimeException when no gateway can take it — loudly, with the
     *                          reason, rather than silently selling something
     *                          the platform cannot renew
     */
    public function start(User $user, Plan $plan, string $currency, ?string $country = null): array
    {
        $price = $plan->priceIn($currency);

        if (! $price) {
            throw new RuntimeException(__('That plan is not on sale in :currency.', ['currency' => $currency]));
        }

        $renewal = $plan->billing_cycle === 'none' ? 'none' : 'manual';

        $decision = $this->selector->select('subscription', $currency, $country, $plan->getKey(), $renewal);

        if (! $decision->chosen()) {
            throw new RuntimeException($decision->explainFailure());
        }

        $payment = $this->reserve($user, $plan, $decision, (float) $price->amount, $currency);

        // Already in flight from a double-submitted form: reuse it rather than
        // creating a second charge.
        if ($payment->gateway_order_id !== null) {
            return ['payment' => $payment, 'session' => $this->resume($payment)];
        }

        $adapter = $this->registry->for($decision->gateway);

        $session = $adapter->createCheckout(new CheckoutRequest(
            reference: $payment->uuid,
            amount: (float) $payment->presentment_amount,
            currency: $payment->presentment_currency,
            description: $plan->name,
            customerEmail: (string) $user->email,
            customerName: $user->name,
            returnUrl: route('checkout.return', $payment),
            cancelUrl: route('billing'),
            metadata: ['payment_uuid' => $payment->uuid, 'user_id' => (string) $user->getKey()],
        ));

        $payment->forceFill([
            'gateway_order_id' => $session->orderId ?? $session->gatewayReference,
            'status' => Payment::STATUS_PENDING,
        ])->save();

        $this->record($payment, PaymentTransaction::CREATED, [
            'order_id' => $session->orderId,
            'mode' => $session->mode,
        ]);

        return ['payment' => $payment, 'session' => $session];
    }

    /**
     * Reserve the payment row.
     *
     * The idempotency key is derived from WHAT IS BEING BOUGHT, not from a
     * random value: two submissions of the same checkout form produce the same
     * key, find the same row, and cannot charge twice.
     */
    private function reserve(User $user, Plan $plan, GatewayDecision $decision, float $amount, string $currency): Payment
    {
        $gateway = $decision->gateway;

        $key = implode(':', [
            'sub', $user->getKey(), $plan->getKey(), strtoupper($currency),
            // A new attempt each hour, so a customer who genuinely wants to
            // try again after a failure is not blocked by their own first try.
            now()->format('YmdH'),
        ]);

        return Payment::firstOrCreate(
            ['idempotency_key' => $key],
            [
                'user_id' => $user->getKey(),
                'plan_id' => $plan->getKey(),
                'gateway_id' => $gateway->getKey(),
                'mode' => $gateway->mode,
                'purpose' => 'subscription',
                'presentment_amount' => $amount,
                'presentment_currency' => strtoupper($currency),
                'status' => Payment::STATUS_CREATED,
                'metadata' => ['plan' => $plan->name],
            ],
        );
    }

    private function resume(Payment $payment): CheckoutSession
    {
        $adapter = $this->registry->for($payment->gateway);
        $credential = $payment->gateway?->activeCredential();

        return new CheckoutSession(
            gatewayReference: (string) $payment->gateway_order_id,
            mode: $adapter?->checkoutMode() ?? 'redirect',
            publicConfig: [
                'key' => $credential?->publishable_key ?? $credential?->secretValue('key_id'),
                'order_id' => $payment->gateway_order_id,
                'currency' => $payment->presentment_currency,
                'name' => (string) settings('branding.app_name'),
            ],
            orderId: $payment->gateway_order_id,
        );
    }

    // -- the one idempotent handler ------------------------------------------

    /**
     * Ask the gateway what happened, and act on it exactly once.
     *
     * @param  string  $via  which path called this, for the timeline
     */
    public function settle(Payment $payment, string $via = PaymentTransaction::RECONCILED): Payment
    {
        $adapter = $this->registry->for($payment->gateway);

        if (! $adapter) {
            return $payment;
        }

        $reference = $payment->gateway_payment_id ?: $payment->gateway_order_id;

        if (! $reference) {
            return $payment;
        }

        try {
            // THE AUTHORITY. Not the browser, not the webhook body — the
            // gateway, asked directly, every time.
            $status = $adapter->fetchTransaction($reference);
        } catch (GatewayFailed $e) {
            $payment->forceFill(['last_checked_at' => now()])->save();

            $this->record($payment, $via, ['error_class' => $e->errorClass]);

            return $payment;
        }

        $this->record($payment, $via, ['status' => $status->status]);

        return $status->isPaid()
            ? $this->markPaid($payment, $status)
            : $this->markUnpaid($payment, $status);
    }

    /**
     * Everything that follows a successful payment, once.
     *
     * The payment row is locked for the duration, so two webhooks arriving
     * together cannot both read "not yet paid" and both grant a month of
     * credits. Inside the lock, every step is separately idempotent as well —
     * belt and braces, because this is where money is.
     */
    private function markPaid(Payment $payment, TransactionStatus $status): Payment
    {
        return DB::transaction(function () use ($payment, $status) {
            /** @var Payment $locked */
            $locked = Payment::whereKey($payment->getKey())->lockForUpdate()->first();

            if ($locked->isPaid()) {
                // Already done. A replayed webhook lands here and does nothing,
                // which is exactly what should happen.
                return $locked;
            }

            $base = $this->inBaseCurrency(
                (float) $locked->presentment_amount,
                $locked->presentment_currency,
            );

            $locked->forceFill([
                'status' => Payment::STATUS_PAID,
                'gateway_payment_id' => $status->gatewayPaymentId ?: $locked->gateway_payment_id,
                'settlement_amount' => $status->settlementAmount,
                'settlement_currency' => $status->settlementCurrency,
                'base_amount' => $base['amount'],
                'exchange_rate_used' => $base['rate'],
                'paid_at' => now(),
                'last_checked_at' => now(),
            ])->save();

            $subscription = $this->activateSubscription($locked);
            $this->issueInvoice($locked, $subscription);

            return $locked->fresh();
        });
    }

    private function markUnpaid(Payment $payment, TransactionStatus $status): Payment
    {
        // A pending payment stays pending: the sweep will ask again. Only a
        // definite failure closes it, because closing early would abandon a
        // customer whose bank was simply slow.
        $payment->forceFill([
            'status' => $status->status === TransactionStatus::FAILED
                ? Payment::STATUS_FAILED
                : Payment::STATUS_PENDING,
            'gateway_payment_id' => $status->gatewayPaymentId ?: $payment->gateway_payment_id,
            'failure_code' => $status->failureCode,
            'failure_reason' => $status->failureReason,
            'last_checked_at' => now(),
        ])->save();

        return $payment;
    }

    /**
     * Start or extend the subscription this payment bought.
     *
     * The period's credits are granted by `activatePeriod()`, whose unique
     * index on (subscription, period start) is the guard that makes a replayed
     * payment event grant nothing the second time.
     */
    private function activateSubscription(Payment $payment): ?Subscription
    {
        $plan = $payment->plan;

        if (! $plan) {
            return null;
        }

        $user = $payment->user;
        $existing = Subscription::where('user_id', $user->getKey())->live()->latest('id')->first();

        if ($existing && $existing->plan_id === $plan->getKey()) {
            // A renewal of the plan they are already on.
            $start = $existing->current_period_end && $existing->current_period_end->isFuture()
                ? $existing->current_period_end
                : now();

            $this->subscriptions->renew($existing, $start);

            $subscription = $existing->fresh();
        } elseif ($existing) {
            // Moving to a different plan: the paid change applies now.
            $this->subscriptions->changePlan($existing, $plan, $payment->presentment_currency);

            $subscription = $existing->fresh();
        } else {
            $subscription = $this->subscriptions->start($user, $plan);
        }

        $subscription->forceFill([
            'gateway_id' => $payment->gateway_id,
            'currency' => $payment->presentment_currency,
            'amount' => (float) $payment->presentment_amount,
        ])->save();

        $payment->forceFill(['subscription_id' => $subscription->getKey()])->save();

        return $subscription;
    }

    /**
     * Issue the invoice for this payment, once.
     *
     * A number is allocated only here — at the moment an invoice is genuinely
     * issued — so a failed attempt never burns one and leaves a gap.
     */
    private function issueInvoice(Payment $payment, ?Subscription $subscription): ?Invoice
    {
        if ($payment->invoice_id) {
            return $payment->invoice;
        }

        $draft = $this->invoices->draft(
            $payment->user,
            $payment->presentment_currency,
            [[
                'description' => (string) ($payment->plan?->name ?? __('Payment')),
                'unit_amount' => (float) $payment->presentment_amount,
            ]],
            $subscription?->getKey(),
        );

        $invoice = $this->invoices->issue($draft);
        $this->invoices->markPaid($invoice, $payment->paid_at ?? now());

        $payment->forceFill(['invoice_id' => $invoice->getKey()])->save();

        return $invoice;
    }

    /**
     * The payment in the owner's reporting currency, at the dated rate.
     *
     * @return array{amount: ?float, rate: ?float}
     */
    private function inBaseCurrency(float $amount, string $currency): array
    {
        $base = strtoupper((string) settings('billing.base_currency'));
        $rate = ExchangeRate::rateOn($currency, $base, Carbon::now());

        // A missing rate records nothing rather than an invented figure — the
        // same rule the usage rollup follows, for the same reason.
        return $rate === null
            ? ['amount' => null, 'rate' => null]
            : ['amount' => round($amount * $rate, 6), 'rate' => $rate];
    }

    /** @param array<string, mixed> $payload */
    public function record(Payment $payment, string $type, array $payload = []): PaymentTransaction
    {
        return PaymentTransaction::create([
            'payment_id' => $payment->getKey(),
            'gateway_id' => $payment->gateway_id,
            'gateway_transaction_id' => $payment->gateway_payment_id,
            'gateway_reference' => $payment->gateway_order_id,
            'type' => $type,
            'amount' => $payment->presentment_amount,
            'currency' => $payment->presentment_currency,
            'status' => $payment->status,
            'raw_payload' => $payload,
        ]);
    }

    /** A stable idempotency key for anything else that needs one. */
    public static function key(string ...$parts): string
    {
        return Str::limit(implode(':', $parts), 90, '');
    }
}
