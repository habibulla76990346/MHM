<?php

namespace App\Domains\Billing\Services;

use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Models\Subscription;
use App\Domains\Billing\Models\SubscriptionPeriod;
use App\Domains\Billing\Support\ProrationResult;
use App\Domains\Credits\Models\CreditLedgerEntry;
use App\Domains\Credits\Services\CreditService;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Subscriptions: start, renew, change, cancel (§19, §20).
 *
 * THE ACTIVATION GUARD IS THE IMPORTANT PART. Granting a period's credits is
 * driven by payment events, and payment events arrive more than once — a
 * webhook retried, a reconciliation sweep catching up, a customer refreshing a
 * return URL. `subscription_periods` has a unique index on (subscription,
 * period start); the second attempt hits it and grants nothing.
 *
 * The check is in the DATABASE and not in an `exists()` here, because two PHP
 * processes can both pass that test in the same millisecond and both grant.
 */
class SubscriptionService
{
    public function __construct(private readonly CreditService $credits) {}

    /**
     * Everyone has a subscription.
     *
     * New accounts land on the default plan, and so does an existing account
     * the first time this is asked — which is how billing can be switched on
     * without anybody's chat stopping working.
     */
    public function ensureSubscription(User $user): ?Subscription
    {
        $existing = Subscription::where('user_id', $user->getKey())->live()->latest('id')->first();

        if ($existing) {
            return $existing;
        }

        $plan = Plan::default();

        if (! $plan) {
            // No default plan configured yet. Not an error: the platform runs
            // unmetered until the owner publishes one.
            return null;
        }

        return $this->start($user, $plan);
    }

    public function start(User $user, Plan $plan, ?\DateTimeInterface $at = null): Subscription
    {
        $at = $at ? Carbon::instance(Carbon::parse($at)->toDateTime()) : now();
        $end = $this->periodEnd($plan, $at);

        return DB::transaction(function () use ($user, $plan, $at, $end) {
            $trialEnds = $plan->trial_days > 0 ? $at->copy()->addDays($plan->trial_days) : null;

            $subscription = Subscription::create([
                'user_id' => $user->getKey(),
                'plan_id' => $plan->getKey(),
                'status' => $trialEnds ? Subscription::STATUS_TRIALING : Subscription::STATUS_ACTIVE,
                'current_period_start' => $at,
                'current_period_end' => $end,
                'trial_ends_at' => $trialEnds,
                'currency' => $plan->prices->first()?->currency,
                'amount' => (float) ($plan->prices->first()?->amount ?? 0),
                'renewal_mechanism' => $plan->billing_cycle === 'none' ? 'none' : 'manual',
            ]);

            $this->activatePeriod($subscription, $at, $end, 'subscription:start');

            return $subscription->fresh('plan');
        });
    }

    /**
     * Grant a period's credits, exactly once.
     *
     * Returns null when this period was already activated — which is a
     * SUCCESS, not a failure: it means the guard did its job and a retried
     * webhook did not pay out twice.
     */
    public function activatePeriod(
        Subscription $subscription,
        \DateTimeInterface $start,
        \DateTimeInterface $end,
        ?string $reference = null,
        ?float $credits = null,
    ): ?SubscriptionPeriod {
        $plan = $subscription->plan;
        $credits ??= (float) ($plan->credits_per_period ?? 0);

        try {
            return DB::transaction(function () use ($subscription, $start, $end, $reference, $credits, $plan) {
                $period = SubscriptionPeriod::create([
                    'subscription_id' => $subscription->getKey(),
                    'period_start' => $start,
                    'period_end' => $end,
                    'credits_granted' => $credits,
                    'reference' => $reference,
                    'activated_at' => now(),
                ]);

                if ($credits > 0) {
                    // Credits that do not roll over are cleared first, so an
                    // unused allowance does not silently accumulate on a plan
                    // whose owner said it should not.
                    if (! $plan->credits_rollover) {
                        $this->clearUnusedAllowance($subscription->user, $plan);
                    }

                    $this->credits->grant(
                        user: $subscription->user,
                        amount: $credits,
                        reason: __(':plan — credits for this period', ['plan' => $plan->name]),
                        referenceType: 'subscription_period',
                        referenceId: (string) $period->getKey(),
                        expiresAt: $plan->credit_expiry_days
                            ? Carbon::parse($start)->addDays($plan->credit_expiry_days)
                            : null,
                    );
                }

                return $period;
            });
        } catch (QueryException $e) {
            // The unique index fired: this period is already active. Exactly
            // what should happen on a replayed webhook.
            if ($this->isDuplicate($e)) {
                return null;
            }

            throw $e;
        }
    }

    public function renew(Subscription $subscription, ?\DateTimeInterface $at = null): ?SubscriptionPeriod
    {
        $at = $at ? Carbon::parse($at) : ($subscription->current_period_end ?? now());
        $end = $this->periodEnd($subscription->plan, $at);

        $subscription->forceFill([
            'current_period_start' => $at,
            'current_period_end' => $end,
            'status' => Subscription::STATUS_ACTIVE,
        ])->save();

        return $this->activatePeriod($subscription, $at, $end, 'subscription:renew');
    }

    // -- changing plan -------------------------------------------------------

    /**
     * What moving to another plan would cost, without doing it.
     *
     * UPGRADES TAKE EFFECT NOW and are charged the difference: the customer
     * asked for more and should get it immediately. DOWNGRADES TAKE EFFECT AT
     * THE PERIOD END — they already paid for this period, and cutting them
     * down early would be keeping money for a service withdrawn.
     */
    public function preview(Subscription $subscription, Plan $target, ?string $currency = null): ProrationResult
    {
        $current = $subscription->plan;
        $currency = strtoupper($currency ?: ($subscription->currency ?: ($target->prices->first()?->currency ?? 'INR')));

        $currentAmount = (float) ($current->priceIn($currency)?->amount ?? $subscription->amount ?? 0);
        $targetAmount = (float) ($target->priceIn($currency)?->amount ?? 0);

        $fraction = $subscription->unusedFraction();
        $unusedCredit = round($currentAmount * $fraction, 6);
        $isUpgrade = $targetAmount > $currentAmount;

        return new ProrationResult(
            from: $current,
            to: $target,
            currency: $currency,
            newPlanAmount: $targetAmount,
            // Only an upgrade consumes the unused value; a downgrade simply
            // starts later, so nothing is credited and nothing is refunded.
            unusedCredit: $isUpgrade ? $unusedCredit : 0.0,
            amountDue: $isUpgrade ? max(0.0, round($targetAmount - $unusedCredit, 6)) : 0.0,
            // Credits follow the same logic: the part of the new allowance
            // covering the time that is left.
            creditsGranted: $isUpgrade ? round((float) $target->credits_per_period * $fraction, 6) : 0.0,
            isUpgrade: $isUpgrade,
            effectiveAt: $isUpgrade ? now() : ($subscription->current_period_end ?? now()),
            unusedFraction: $fraction,
        );
    }

    /**
     * Apply a plan change.
     *
     * The money side (charging `amountDue`) belongs to the payment layer; this
     * moves the subscription and the entitlements.
     */
    public function changePlan(Subscription $subscription, Plan $target, ?string $currency = null): ProrationResult
    {
        $proration = $this->preview($subscription, $target, $currency);

        if (! $proration->isUpgrade) {
            // Scheduled, not applied. The customer keeps what they paid for
            // until the period they paid for ends.
            $subscription->forceFill([
                'pending_plan_id' => $target->getKey(),
                'pending_plan_starts_at' => $proration->effectiveAt,
            ])->save();

            return $proration;
        }

        DB::transaction(function () use ($subscription, $target, $proration) {
            $subscription->forceFill([
                'plan_id' => $target->getKey(),
                'currency' => $proration->currency,
                'amount' => $proration->newPlanAmount,
                'status' => Subscription::STATUS_ACTIVE,
            ])->save();

            if ($proration->creditsGranted > 0) {
                $this->credits->grant(
                    user: $subscription->user,
                    amount: $proration->creditsGranted,
                    reason: __('Upgraded to :plan — credits for the rest of this period', ['plan' => $target->name]),
                    referenceType: 'subscription_upgrade',
                    referenceId: (string) $subscription->getKey(),
                );
            }
        });

        return $proration;
    }

    /**
     * Cancel.
     *
     * By default at the end of the paid period, because cancelling is not a
     * refund. Immediate cancellation exists for support to use deliberately.
     */
    public function cancel(Subscription $subscription, bool $immediately = false): Subscription
    {
        $subscription->forceFill([
            'status' => Subscription::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancel_at' => $immediately ? now() : $subscription->current_period_end,
            'ended_at' => $immediately ? now() : null,
        ])->save();

        if ($immediately) {
            $subscription->forceFill(['status' => Subscription::STATUS_EXPIRED])->save();
        }

        return $subscription->fresh();
    }

    /**
     * Apply a downgrade that was waiting for the period to end.
     *
     * Run by the scheduler. Until it fires the customer keeps the plan they
     * paid for, which is the whole reason a downgrade is scheduled rather than
     * applied when they click.
     */
    public function applyScheduledChanges(): int
    {
        $applied = 0;

        Subscription::whereNotNull('pending_plan_id')
            ->whereNotNull('pending_plan_starts_at')
            ->where('pending_plan_starts_at', '<=', now())
            ->with('plan')
            ->chunkById(100, function ($subscriptions) use (&$applied) {
                foreach ($subscriptions as $subscription) {
                    $target = Plan::find($subscription->pending_plan_id);

                    if (! $target) {
                        $subscription->forceFill(['pending_plan_id' => null, 'pending_plan_starts_at' => null])->save();

                        continue;
                    }

                    $subscription->forceFill([
                        'plan_id' => $target->getKey(),
                        'amount' => (float) ($target->priceIn((string) $subscription->currency)?->amount ?? 0),
                        'pending_plan_id' => null,
                        'pending_plan_starts_at' => null,
                    ])->save();

                    $applied++;
                }
            });

        return $applied;
    }

    /**
     * End one subscription. The only place a subscription becomes `expired`.
     *
     * Two situations lead here and they are deliberately kept apart:
     * `expireLapsed()` below ends a CANCELLED one when its paid time runs out,
     * and `RenewalService::endAfterGrace()` ends an UNPAID one after its grace
     * period. Different timing, different messaging, one implementation — so
     * the act of ending cannot drift between them.
     */
    public function expire(Subscription $subscription): Subscription
    {
        $subscription->forceFill([
            'status' => Subscription::STATUS_EXPIRED,
            'ended_at' => now(),
        ])->save();

        return $subscription;
    }

    /**
     * End cancelled subscriptions whose paid period has run out.
     *
     * CANCELLED ONLY. An unpaid one (`past_due`) is not ended here: it has a
     * grace period and a customer who is being reminded, and both belong to
     * `RenewalService`. Ending it here as well would mean two schedules
     * racing to close the same account — and the stricter one would always
     * win, quietly cancelling the grace period the owner configured.
     */
    public function expireLapsed(): int
    {
        $count = 0;

        Subscription::where('status', Subscription::STATUS_CANCELLED)
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '<', now())
            ->chunkById(100, function ($subscriptions) use (&$count) {
                foreach ($subscriptions as $subscription) {
                    $this->expire($subscription);
                    $count++;
                }
            });

        return $count;
    }

    // -- internals -----------------------------------------------------------

    private function periodEnd(Plan $plan, Carbon|\DateTimeInterface $start): Carbon
    {
        $start = Carbon::parse($start);

        return match ($plan->billing_cycle) {
            'yearly' => $start->copy()->addYear(),
            'lifetime' => $start->copy()->addYears(100),
            // A free plan still has periods — that is how its allowance
            // refreshes each month.
            default => $start->copy()->addMonth(),
        };
    }

    /**
     * Clear an allowance that does not roll over.
     *
     * Only what came from a subscription period, and never below zero: credit
     * the customer bought as a top-up is theirs and is not swept away by a
     * plan's rollover setting.
     */
    private function clearUnusedAllowance(User $user, Plan $plan): void
    {
        $balance = $this->credits->balance($user);
        $unused = (float) $balance->confirmed_balance;

        if ($unused <= 0) {
            return;
        }

        $fromAllowance = (float) CreditLedgerEntry::where('user_id', $user->getKey())
            ->where('reference_type', 'subscription_period')
            ->where('amount', '>', 0)
            ->sum('amount');

        $spentSince = min($unused, $fromAllowance);

        if ($spentSince <= 0) {
            return;
        }

        $this->credits->expire(
            user: $user,
            amount: min($unused, $spentSince),
            reason: __('Unused allowance cleared — :plan does not carry credits over', ['plan' => $plan->name]),
            referenceType: 'allowance_reset',
            referenceId: (string) $plan->getKey(),
        );
    }

    private function isDuplicate(QueryException $e): bool
    {
        // 23000 covers integrity constraint violations across MySQL, MariaDB,
        // SQLite and Postgres, so this is not a driver-specific branch.
        return $e->getCode() === '23000' || str_contains($e->getMessage(), 'Duplicate entry');
    }
}
