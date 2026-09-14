<?php

use App\Domains\AI\Jobs\AggregateDailyUsageJob;
use App\Domains\AI\Jobs\RefreshExchangeRatesJob;
use App\Domains\Billing\Services\RenewalService;
use App\Domains\Billing\Services\SubscriptionService;
use App\Domains\Credits\Services\CreditService;
use App\Domains\Diagnostics\Checks\SchedulerCheck;
use App\Domains\Diagnostics\Models\DiagnosticRun;
use App\Domains\Diagnostics\Services\DiagnosticRunner;
use App\Domains\Images\Services\MediaRetentionService;
use App\Domains\Notifications\Services\AnnouncementService;
use App\Domains\Payments\Services\ReconciliationService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
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
 * Renewals for subscriptions that are paid by invoice (Addendum D §3).
 *
 * DAILY, and it does four things in one pass: issue the invoice and mail the
 * payment link before the period ends, renew a free plan that has nothing to
 * invoice, mark an unpaid one overdue while it keeps working, and end it only
 * once the grace period the owner configured has run out.
 *
 * The window it looks back over has no floor, so a server that was switched
 * off for a fortnight bills the invoices it owes rather than skipping them — a
 * customer who was never asked for money must never be treated as one who did
 * not pay.
 */
Schedule::call(fn () => app(RenewalService::class)->run())
    ->dailyAt('06:00')
    ->name('billing:renewals')
    ->withoutOverlapping()
    ->onOneServer();

/**
 * Announcements whose scheduled time has come (§22).
 *
 * Hourly rather than by the minute: an owner scheduling a maintenance notice
 * picks an hour, not a second, and a job that wakes every minute to find
 * nothing is a job a shared host is paying for.
 */
Schedule::call(fn () => app(AnnouncementService::class)->sendDue())
    ->hourly()
    ->name('announcements:send-due')
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

/**
 * Media retention (§16, §18).
 *
 * Daily, in the quiet hour after the rollups. Generated images go entirely
 * when their time is up; audio goes and its row stays, because the transcript
 * is a chat message the customer owns and the cost is the owner's record.
 *
 * Off by default for images and a week for audio — retention is the owner's
 * decision, and deleting somebody's pictures because nobody chose a number
 * would be the wrong default to have.
 */
Schedule::call(fn () => app(MediaRetentionService::class)->sweep())
    ->dailyAt('01:10')
    ->name('media:retention')
    ->withoutOverlapping()
    ->onOneServer();

/**
 * The scheduler heartbeat (Owner Addendum G).
 *
 * FIRST, AND DELIBERATELY TRIVIAL. Everything time-based in this platform
 * depends on cron: renewal invoices, past-due notices, credit expiry, the
 * reconciliation sweep that catches a customer who closed the browser
 * mid-payment, and — on shared hosting — the queue itself. An owner who never
 * adds the cron line sees a working site while money is taken and nothing is
 * delivered.
 *
 * There is no way to read a crontab from PHP on most hosts, and reading one
 * would prove nothing anyway: the line can be present and the daemon stopped.
 * So the scheduler leaves a timestamp every time it runs, and `SchedulerCheck`
 * does arithmetic on it.
 */
Schedule::call(fn () => Cache::put(SchedulerCheck::HEARTBEAT_KEY, now()->toIso8601String(), now()->addDays(7)))
    ->everyMinute()
    ->name('diagnostics:heartbeat');

/**
 * The nightly health run (Owner Addendum G §7).
 *
 * SAFE BY CONSTRUCTION: `DiagnosticRun::SCHEDULED` excludes every check that
 * costs money or has a side effect, so an unattended run can never spend the
 * owner's provider budget on diagnostics or send a test email at 3am.
 *
 * It emails only when something CHANGES. Alerting on every red would send the
 * same message every night until it was fixed, which is how somebody learns to
 * file these where they never look.
 */
Schedule::call(fn () => app(DiagnosticRunner::class)->run(DiagnosticRun::SCHEDULED))
    ->dailyAt('05:30')
    ->name('diagnostics:nightly')
    ->withoutOverlapping()
    ->onOneServer();
