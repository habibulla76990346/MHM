<?php

use App\Domains\AI\Jobs\AggregateDailyUsageJob;
use App\Domains\AI\Jobs\RefreshExchangeRatesJob;
use App\Domains\Billing\Services\SubscriptionService;
use App\Domains\Credits\Services\CreditService;
use App\Domains\Payments\Services\ReconciliationService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * Scheduled model catalog refresh (§11).
 *
 * Weekly, not daily: providers do not add models often, and each sync is an
 * authenticated call to a third party. Queued rather than run inline, so a
 * shared host running this from cron behaves the same as a VPS with a worker —
 * speed differs, capability does not.
 */
Schedule::command('aziv:models:sync')
    ->weeklyOn(1, '03:00')
    ->withoutOverlapping()
    ->onOneServer();

/**
 * The nightly cost rollup (§21).
 *
 * Runs just after midnight for the day that has just ended, so the figures an
 * owner sees in the morning are complete. Queued, like every long operation,
 * so shared hosting differs in speed and never in capability.
 */
Schedule::job(new AggregateDailyUsageJob)
    ->dailyAt('00:20')
    ->withoutOverlapping()
    ->onOneServer();

/**
 * Exchange rates, before the rollup that uses them (§13).
 *
 * Earlier in the night on purpose: a summary written with yesterday's rate
 * cannot be silently corrected later, because the whole point of freezing the
 * conversion is that the number never moves.
 */
Schedule::job(new RefreshExchangeRatesJob)
    ->dailyAt('00:05')
    ->withoutOverlapping()
    ->onOneServer();

/**
 * Housekeeping the credit system needs to stay honest (§19).
 *
 * Holds first: a worker that died mid-call would otherwise reserve a
 * customer's balance for ever, and they would see credit they cannot spend
 * with no way to find out why.
 */
Schedule::call(fn () => app(CreditService::class)->releaseExpiredHolds())
    ->everyTenMinutes()
    ->name('credits:release-expired-holds')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::call(fn () => app(CreditService::class)->expireCredits())
    ->dailyAt('00:40')
    ->name('credits:expire')
    ->withoutOverlapping()
    ->onOneServer();

/**
 * Subscriptions: apply the downgrades that were waiting for a period to end,
 * and close out the ones whose paid time has run out.
 */
Schedule::call(function () {
    $subscriptions = app(SubscriptionService::class);
    $subscriptions->applyScheduledChanges();
    $subscriptions->expireLapsed();
})
    ->hourly()
    ->name('subscriptions:advance')
    ->withoutOverlapping()
    ->onOneServer();

/**
 * The reconciliation sweep (Addendum D §4.1).
 *
 * Every few minutes, because this is what makes the system correct when a
 * customer closes the browser mid-payment and the webhook is delayed or lost.
 * Without it, money is taken and nothing is delivered — and the first anyone
 * hears of it is a support ticket.
 */
Schedule::call(fn () => app(ReconciliationService::class)->sweep())
    ->everyFiveMinutes()
    ->name('payments:reconcile')
    ->withoutOverlapping()
    ->onOneServer();
