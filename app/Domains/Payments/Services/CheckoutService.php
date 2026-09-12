<?php

namespace App\Domains\Payments\Services;

use App\Domains\AI\Models\ExchangeRate;
use App\Domains\Billing\Models\Invoice;
use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Models\Subscription;
use App\Domains\Billing\Services\InvoiceService;
use App\Domains\Billing\Services\RenewalService;
use App\Domains\Billing\Services\SubscriptionService;
use App\Domains\Notifications\Services\Notifier;
use App\Domains\Notifications\Support\NotificationEvent;
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
        private readonly Notifier $notifier,
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

        return ['payment' => $payment, 'session' => $this->openCheckout($payment, $plan->name)];
    }

    /**
     * Reserve the payment for a renewal invoice that has already been issued.
     *
     * SEPARATE FROM `start()` IN WHAT IT RESERVES, IDENTICAL IN WHAT HAPPENS
     * NEXT. A renewal is not a new sale: the invoice exists, the amount is
     * already fixed by it, and the customer may open the link days later. So
     * this creates the local row only — no gateway order is opened until
     * somebody actually clicks, because an order created a week early is an
     * order most gateways will have expired by then.
     *
     * The idempotency key is the SUBSCRIPTION AND THE PERIOD, not the hour:
     * one renewal payment per period however many times this is called, and
     * a reminder sent a second time reaches the same payment and the same
     * invoice.
     */
    public function reserveRenewal(Subscription $subscription, Invoice $invoice): Payment
    {
        $currency = strtoupper((string) $invoice->currency);

        $decision = $this->selector->select(
            'subscription',
            $currency,
            $invoice->customer_country,
            $subscription->plan_id,
            (string) ($subscription->renewal_mechanism ?: 'manual'),
        );

        if (! $decision->chosen()) {
            throw new RuntimeException($decision->explainFailure());
        }

        $key = self::key('renew', (string) $subscription->getKey(), (string) $invoice->renewal_period_start?->timestamp);

        $payment = Payment::firstOrCreate(
            ['idempotency_key' => $key],
            [
                'user_id' => $subscription->user_id,
                'subscription_id' => $subscription->getKey(),
                'plan_id' => $subscription->plan_id,
                'invoice_id' => $invoice->getKey(),
                'gateway_id' => $decision->gateway->getKey(),
                'mode' => $decision->gateway->mode,
                'purpose' => Payment::PURPOSE_RENEWAL,
                'presentment_amount' => (float) $invoice->total,
                'presentment_currency' => $currency,
                'status' => Payment::STATUS_CREATED,
                'metadata' => ['invoice' => $invoice->number],
            ],
        );

        // `firstOrCreate` returns a thin model on insert — it holds only what
        // was passed, while the database holds the column defaults.
        return $payment->refresh();
    }

    /**
     * Open the gateway's checkout for a payment that is already reserved.
     *
     * One implementation for both paths. A payment already in flight — a
     * double-submitted form, a link opened twice — RESUMES rather than
     * creating a second charge.
     */
    public function openCheckout(Payment $payment, ?string $description = null, ?string $returnUrl = null): CheckoutSession
    {
        if ($payment->gateway_order_id !== null) {
            return $this->resume($payment);
        }

        $adapter = $this->registry->for($payment->gateway);

        if (! $adapter) {
            throw new RuntimeException(__('This payment cannot be opened: its gateway is no longer installed.'));
        }

        $user = $payment->user;

        $session = $adapter->createCheckout(new CheckoutRequest(
            reference: $payment->uuid,
            amount: (float) $payment->presentment_amount,
            currency: $payment->presentment_currency,
            description: (string) ($description ?: $payment->plan?->name ?: __('Payment')),
            customerEmail: (string) $user?->email,
            customerName: $user?->name,
            returnUrl: $returnUrl ?: route('checkout.return', $payment),
            cancelUrl: route('billing'),
            metadata: ['payment_uuid' => $payment->uuid, 'user_id' => (string) $payment->user_id],
        ));

        $payment->forceFill([
            'gateway_order_id' => $session->orderId ?? $session->gatewayReference,
            'status' => Payment::STATUS_PENDING,
        ])->save();

        $this->record($payment, PaymentTransaction::CREATED, [
            'order_id' => $session->orderId,
            'mode' => $session->mode,
        ]);

        return $session;
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
                'purpose' => Payment::PURPOSE_SUBSCRIPTION,
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
        [$settled, $justPaid] = DB::transaction(function () use ($payment, $status) {
            /** @var Payment $locked */
            $locked = Payment::whereKey($payment->getKey())->lockForUpdate()->first();

            if ($locked->isPaid()) {
                // Already done. A replayed webhook lands here and does nothing,
                // which is exactly what should happen.
                return [$locked, false];
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

            return [$locked->fresh(), true];
        });

        // OUTSIDE THE TRANSACTION, and only on the attempt that actually
        // changed something. Inside it, a queued job could be picked up by a
        // worker before the commit landed and find nothing; on a replay, it
        // would thank the customer a second time for one payment.
        if ($justPaid) {
            $this->announcePayment($settled);
        }

        return $settled;
    }

    /**
     * Tell the customer their money arrived.
     *
     * WHAT IT SAYS is the amount, the plan, the invoice number and how long
     * they are paid up for. WHAT IT NEVER SAYS is anything about the
     * instrument — no card, no last four, no bank. Aziv AI does not hold those
     * and an email is forwarded, printed and stored on servers nobody here
     * controls.
     */
    private function announcePayment(Payment $payment): void
    {
        $user = $payment->user;

        if (! $user) {
            return;
        }

        $payment->loadMissing(['plan', 'invoice', 'subscription']);

        $this->notifier->send($user, NotificationEvent::PAYMENT_RECEIVED, [
            'amount' => strtoupper((string) $payment->presentment_currency).' '
                .number_format((float) $payment->presentment_amount, 2),
            'plan' => (string) ($payment->plan?->name ?: __('your subscription')),
            'invoice_number' => (string) $payment->invoice?->number,
            'period_end' => optional($payment->subscription?->current_period_end)->toFormattedDateString() ?: '',
        ], $payment->invoice ?: $payment);
    }

    private function markUnpaid(Payment $payment, TransactionStatus $status): Payment
    {
        $wasOpen = ! in_array($payment->status, [Payment::STATUS_FAILED, Payment::STATUS_PAID], true);

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

        // Once, on the transition. The sweep asks repeatedly, and a customer
        // must not get an email every five minutes about one failed card.
        //
        // The gateway's own reason is NOT passed on. We are rarely told the
        // real one, and repeating a bank's terse code to a customer sends
        // them to argue with the wrong people.
        if ($wasOpen && $payment->status === Payment::STATUS_FAILED && $payment->user) {
            $this->notifier->send($payment->user, NotificationEvent::PAYMENT_FAILED, [
                'amount' => strtoupper((string) $payment->presentment_currency).' '
                    .number_format((float) $payment->presentment_amount, 2),
                'plan' => (string) ($payment->plan?->name ?: __('your subscription')),
                'pay_url' => $payment->isRenewal()
                    ? app(RenewalService::class)->payUrl($payment)
                    : route('billing'),
            ], $payment);
        }

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
            // A RENEWAL ARRIVES WITH ITS INVOICE. It was issued days earlier
            // and mailed to the customer; paying it settles that document
            // rather than raising a second one for the same period. Marking
            // paid is idempotent, so a replayed webhook changes nothing.
            $existing = $payment->invoice;

            if ($existing && ! $existing->isPaid()) {
                $this->invoices->markPaid($existing, $payment->paid_at ?? now());
            }

            return $existing;
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
