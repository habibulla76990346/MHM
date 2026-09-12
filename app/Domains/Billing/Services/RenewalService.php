<?php

namespace App\Domains\Billing\Services;

use App\Domains\Billing\Models\Invoice;
use App\Domains\Billing\Models\Subscription;
use App\Domains\Billing\Support\RenewalNotice;
use App\Domains\Notifications\Services\Notifier;
use App\Domains\Notifications\Support\NotificationEvent;
use App\Domains\Payments\Models\Payment;
use App\Domains\Payments\Services\CheckoutService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * Renewing a subscription that is paid by invoice (Owner Addendum D §3).
 *
 * WHY THIS EXISTS. A gateway that cannot do recurring billing is never handed
 * a subscription — the capability guard sees to that. What it is handed
 * instead is "manual renewal", and Addendum D describes that as the customer
 * receiving "an invoice and a payment link each period". Without this class
 * that sentence was aspirational: a subscription simply ran out of credits and
 * the customer's first news of it was losing access.
 *
 * THE LIFECYCLE, and every step of it is something the customer is told:
 *
 *   period end − notice days   the invoice is issued and mailed with a link
 *   period end                 unpaid → past due; still working, reminded
 *   period end + grace days    unpaid → ended, and they are told that too
 *   paid, at any point         the ordinary payment path renews it
 *
 * IDEMPOTENCE IS IN THE DATABASE. `invoices` has a unique index on
 * (subscription, renewal period start), so the scheduler overlapping with an
 * administrator pressing "send it now" produces one invoice and one number.
 * A check in PHP would let two processes both pass it in the same
 * millisecond — the same reasoning as the subscription-period guard.
 *
 * IT RAISES NO MONEY OF ITS OWN. The invoice comes from `InvoiceService`, the
 * payment and the gateway from `CheckoutService`, the renewal itself from
 * `SubscriptionService`. This decides WHEN, never HOW — there is one billing
 * system and this is not a second one.
 */
class RenewalService
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly InvoiceService $invoices,
        private readonly CheckoutService $checkout,
        private readonly Notifier $notifier,
    ) {}

    /**
     * One pass of the whole lifecycle. Run by the scheduler.
     *
     * @return array{invoiced: int, renewed_free: int, overdue: int, ended: int}
     */
    public function run(): array
    {
        return [
            'invoiced' => $this->issueUpcoming(),
            'renewed_free' => $this->renewFreePeriods(),
            'overdue' => $this->markOverdue(),
            'ended' => $this->endAfterGrace(),
        ];
    }

    // -- 1. the invoice and the link -----------------------------------------

    /**
     * Issue and send the renewal for every subscription close enough to need
     * one.
     *
     * The window has no lower bound on purpose. A server that was switched off
     * for a fortnight must catch up on the invoices it owes rather than skip
     * them: a customer who was never billed must not be treated as a customer
     * who did not pay.
     */
    public function issueUpcoming(): int
    {
        $sent = 0;

        $this->dueForNotice()->chunkById(100, function ($subscriptions) use (&$sent) {
            foreach ($subscriptions as $subscription) {
                if ($this->amountFor($subscription) <= 0) {
                    continue; // handled by renewFreePeriods()
                }

                $notice = $this->prepare($subscription);

                if ($notice?->isNew) {
                    $this->notify($notice, NotificationEvent::RENEWAL_DUE);
                    $sent++;
                }
            }
        });

        return $sent;
    }

    /** Manual subscriptions whose current period ends within the notice window. */
    public function dueForNotice(): Builder
    {
        $horizon = now()->addDays((int) settings('billing.renewal_notice_days'));

        return Subscription::query()
            ->where('renewal_mechanism', 'manual')
            // A cancelled subscription is not chased for money. The customer
            // asked to stop; billing them again would be the opposite of
            // honouring that.
            ->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_TRIALING, Subscription::STATUS_PAST_DUE])
            ->whereNull('cancelled_at')
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '<=', $horizon)
            ->with(['plan', 'user'])
            ->orderBy('id');
    }

    /**
     * The paperwork for this subscription's NEXT period, exactly once.
     *
     * Safe to call repeatedly and from anywhere — the scheduler, an admin
     * action, the customer's own billing page. The second call returns what
     * the first one made.
     */
    public function prepare(Subscription $subscription): ?RenewalNotice
    {
        $plan = $subscription->plan;
        $user = $subscription->user;

        if (! $plan || ! $user || $plan->billing_cycle === 'none') {
            return null;
        }

        $periodStart = Carbon::parse($subscription->current_period_end ?? now());
        $periodEnd = $this->periodEnd($subscription, $periodStart);
        $currency = strtoupper((string) ($subscription->currency ?: settings('billing.base_currency')));
        $amount = $this->amountFor($subscription);

        if ($amount <= 0) {
            return null;
        }

        $existing = Invoice::where('subscription_id', $subscription->getKey())
            ->where('renewal_period_start', $periodStart)
            ->first();

        $isNew = $existing === null;

        $invoice = $existing ?: $this->raise($subscription, $currency, $amount, $periodStart, $periodEnd);

        // Lost the race with another process: it made the invoice, we use it.
        if (! $invoice) {
            $invoice = Invoice::where('subscription_id', $subscription->getKey())
                ->where('renewal_period_start', $periodStart)
                ->firstOrFail();
            $isNew = false;
        }

        $payment = $this->checkout->reserveRenewal($subscription, $invoice);

        return new RenewalNotice(
            subscription: $subscription,
            invoice: $invoice,
            payment: $payment,
            payUrl: $this->payUrl($payment),
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            isNew: $isNew,
        );
    }

    /**
     * The link in the email.
     *
     * SIGNED AND DATED, not secret-by-obscurity. The signature is an HMAC over
     * the whole URL under the application key, so it cannot be edited to point
     * at somebody else's payment, and it stops working on its own — an old
     * email in a forwarded mailbox is not a way in for ever.
     *
     * It is the same mechanism as the email-verification link, which is
     * deliberate: one way of proving "this person received our email", not two.
     */
    public function payUrl(Payment $payment): string
    {
        return URL::temporarySignedRoute(
            'renewal.show',
            now()->addDays((int) settings('billing.renewal_link_days')),
            ['payment' => $payment->uuid],
        );
    }

    // -- 2. a free plan renews itself ----------------------------------------

    /**
     * A plan that costs nothing has nothing to invoice.
     *
     * It still has PERIODS — that is how its allowance refreshes — so leaving
     * it out would mean a free customer's credits stopped arriving with no
     * bill to explain why. Renewing it needs no payment, no link and no email.
     */
    public function renewFreePeriods(): int
    {
        $renewed = 0;

        Subscription::query()
            ->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_TRIALING])
            ->whereNull('cancelled_at')
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '<=', now())
            ->with(['plan', 'user'])
            ->orderBy('id')
            ->chunkById(100, function ($subscriptions) use (&$renewed) {
                foreach ($subscriptions as $subscription) {
                    if (! $subscription->plan || $subscription->plan->billing_cycle === 'none') {
                        continue;
                    }

                    if ($this->amountFor($subscription) > 0) {
                        continue;
                    }

                    $this->subscriptions->renew($subscription, $subscription->current_period_end);
                    $renewed++;
                }
            });

        return $renewed;
    }

    // -- 3. past due, still working ------------------------------------------

    /**
     * The period has ended and the invoice is unpaid.
     *
     * `past_due` still counts as live (see `Subscription::isLive()`), which is
     * the point: a customer who is a day late keeps a working account and gets
     * a link, rather than discovering the problem as a locked door.
     */
    public function markOverdue(): int
    {
        $count = 0;

        Subscription::query()
            ->where('renewal_mechanism', 'manual')
            ->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_TRIALING])
            ->whereNull('cancelled_at')
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '<', now())
            ->with(['plan', 'user'])
            ->orderBy('id')
            ->chunkById(100, function ($subscriptions) use (&$count) {
                foreach ($subscriptions as $subscription) {
                    if ($this->amountFor($subscription) <= 0) {
                        continue; // a free plan renewed itself
                    }

                    $notice = $this->prepare($subscription);

                    if (! $notice || $notice->invoice->isPaid()) {
                        continue;
                    }

                    $subscription->forceFill(['status' => Subscription::STATUS_PAST_DUE])->save();

                    $this->notify($notice, NotificationEvent::RENEWAL_OVERDUE);
                    $count++;
                }
            });

        return $count;
    }

    // -- 4. the end, and only after the grace period -------------------------

    /**
     * Stop an unpaid subscription — after the grace period, and never silently.
     *
     * This is the ONE place an unpaid subscription ends. A cancellation ends
     * differently and is owned by `SubscriptionService::expireLapsed()`: the
     * customer asked to stop, so there is no grace and no chasing. Two
     * lifecycles, one owner each.
     */
    public function endAfterGrace(): int
    {
        $grace = (int) settings('billing.renewal_grace_days');
        $count = 0;

        Subscription::query()
            ->where('status', Subscription::STATUS_PAST_DUE)
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '<', now()->subDays($grace))
            ->with(['plan', 'user'])
            ->orderBy('id')
            ->chunkById(100, function ($subscriptions) use (&$count) {
                foreach ($subscriptions as $subscription) {
                    $this->subscriptions->expire($subscription);

                    if ($subscription->user) {
                        $this->notifier->send(
                            $subscription->user,
                            NotificationEvent::SUBSCRIPTION_EXPIRED,
                            [
                                'plan' => (string) $subscription->plan?->name,
                                'billing_url' => route('billing'),
                            ],
                            $subscription,
                        );
                    }

                    $count++;
                }
            });

        return $count;
    }

    // -- internals -----------------------------------------------------------

    /** @return Invoice|null null when another process won the unique index */
    private function raise(
        Subscription $subscription,
        string $currency,
        float $amount,
        Carbon $periodStart,
        Carbon $periodEnd,
    ): ?Invoice {
        try {
            $draft = $this->invoices->draft(
                $subscription->user,
                $currency,
                [[
                    'description' => __(':plan — :from to :to', [
                        'plan' => (string) $subscription->plan?->name,
                        'from' => $periodStart->toFormattedDateString(),
                        'to' => $periodEnd->toFormattedDateString(),
                    ]),
                    'unit_amount' => $amount,
                ]],
                $subscription->getKey(),
                $periodStart,
            );
        } catch (UniqueConstraintViolationException) {
            // The guard fired. Exactly what should happen when two processes
            // reach the same period at the same moment.
            return null;
        }

        // BEFORE issuing, not after. Once an invoice is issued the model layer
        // refuses every column but `status` and `paid_at` — which is the whole
        // Phase 6 guarantee, and it caught this the first time it ran. The due
        // date is part of what the document says, so it belongs on the draft.
        $draft->forceFill(['due_at' => $periodStart])->save();

        return $this->invoices->issue($draft)->fresh();
    }

    private function notify(RenewalNotice $notice, string $event): void
    {
        $user = $notice->subscription->user;

        if (! $user) {
            return;
        }

        $grace = (int) settings('billing.renewal_grace_days');

        $this->notifier->send($user, $event, [
            'plan' => (string) $notice->subscription->plan?->name,
            'amount' => $this->money((float) $notice->invoice->total, (string) $notice->invoice->currency),
            'currency' => (string) $notice->invoice->currency,
            'invoice_number' => (string) $notice->invoice->number,
            'due_date' => $notice->periodStart->toFormattedDateString(),
            'grace_ends' => $notice->periodStart->copy()->addDays($grace)->toFormattedDateString(),
            'pay_url' => $notice->payUrl,
        ], $notice->invoice);
    }

    private function amountFor(Subscription $subscription): float
    {
        $currency = strtoupper((string) ($subscription->currency ?: settings('billing.base_currency')));

        return (float) ($subscription->plan?->priceIn($currency)?->amount ?? $subscription->amount ?? 0);
    }

    private function periodEnd(Subscription $subscription, Carbon $start): Carbon
    {
        return match ($subscription->plan?->billing_cycle) {
            'yearly' => $start->copy()->addYear(),
            'lifetime' => $start->copy()->addYears(100),
            default => $start->copy()->addMonth(),
        };
    }

    /**
     * An amount as a customer should read it.
     *
     * Currency-code-and-number rather than a symbol: the platform sells in
     * whatever currencies the owner configures, and guessing a symbol for one
     * of them is how "₹" ends up in front of a dollar amount.
     */
    private function money(float $amount, string $currency): string
    {
        return strtoupper($currency).' '.number_format($amount, 2);
    }
}
