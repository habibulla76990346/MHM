<?php

namespace App\Domains\Diagnostics\Checks;

use App\Domains\Diagnostics\Support\Category;
use App\Domains\Diagnostics\Support\CheckResult;
use App\Domains\Diagnostics\Support\Responsibility;
use App\Domains\Diagnostics\Support\Severity;
use App\Domains\Diagnostics\Support\Status;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Is cron actually running? (Owner Addendum G, Phase 9.)
 *
 * THE MOST EXPENSIVE THING THAT CAN FAIL SILENTLY, now that mail is fixed.
 * Everything time-based in this platform hangs off the scheduler: renewal
 * invoices, past-due notices, credit expiry, exchange rates, the payment
 * reconciliation sweep that catches a customer who closed the browser
 * mid-payment, and the queue itself on shared hosting.
 *
 * An owner who never adds the cron line sees a working site. Chat works, the
 * admin panel works, nothing errors. What happens instead is that money is
 * taken and nothing is delivered, subscriptions lapse without a notice, and
 * the first anybody hears of it is a customer asking where their credits went.
 *
 * IT MEASURES A HEARTBEAT, not the configuration. Reading a crontab is
 * impossible from PHP on most hosts and would prove nothing anyway — the line
 * can be there and the daemon stopped. The scheduler writes a timestamp on
 * every tick; this reads it and does arithmetic.
 */
class SchedulerCheck extends BaseCheck
{
    /** Where every scheduler tick leaves its mark. */
    public const HEARTBEAT_KEY = 'aziv:scheduler:last_tick_at';

    /**
     * Minutes of silence before this is a problem.
     *
     * Generous, because a per-minute cron is not universal — plenty of shared
     * hosts offer five- or fifteen-minute granularity, and one missed tick on
     * a busy server is not news. Thirty minutes of nothing is.
     */
    private const STALE_MINUTES = 30;

    /** After this long it is not late, it is not running. */
    private const DEAD_MINUTES = 180;

    public function key(): string
    {
        return 'queue.scheduler';
    }

    public function title(): string
    {
        return 'Scheduled tasks (cron)';
    }

    public function category(): Category
    {
        return Category::QueueCron;
    }

    public function run(): CheckResult
    {
        $last = Cache::get(self::HEARTBEAT_KEY);
        $seenAt = is_string($last) ? Carbon::parse($last) : null;

        if (! $seenAt) {
            return $this->result(
                Status::Red,
                Severity::Critical,
                'The scheduler has never run on this server.',
                'Renewal invoices, past-due notices, credit expiry, exchange rates and the payment '
                    .'reconciliation sweep all depend on it. Without cron, money can be taken and nothing delivered.',
                $this->cronInstruction(),
                supportWording: 'Please add a cron job that runs every minute: '.$this->cronLine(),
            );
        }

        $minutes = (int) $seenAt->diffInMinutes(now());

        if ($minutes >= self::DEAD_MINUTES) {
            return $this->result(
                Status::Red,
                Severity::Critical,
                'The scheduler last ran '.$seenAt->diffForHumans().'.',
                'It has stopped. Everything time-based is no longer happening.',
                $this->cronInstruction(),
                supportWording: 'My scheduled task appears to have stopped running. The cron line is: '.$this->cronLine(),
            );
        }

        if ($minutes >= self::STALE_MINUTES) {
            return $this->result(
                Status::Yellow,
                Severity::High,
                'The scheduler last ran '.$seenAt->diffForHumans().'.',
                'Later than expected. One missed tick is nothing; a pattern of them delays renewals and reconciliation.',
                'Check that the cron job is still enabled and that the server is not overloaded.',
            );
        }

        return $this->result(
            Status::Green,
            Severity::Informational,
            'Last ran '.$seenAt->diffForHumans().'.',
            'Renewals, reconciliation and housekeeping are running on time.',
            'Nothing to do.',
        );
    }

    private function cronLine(): string
    {
        // Relative, because an absolute path here would be this server's and
        // the report is read on somebody else's.
        return '* * * * * cd /path/to/aziv && php artisan schedule:run >> /dev/null 2>&1';
    }

    private function cronInstruction(): string
    {
        return 'In cPanel open "Cron Jobs", choose "Once Per Minute", and set the command to: '.$this->cronLine()
            .' — replacing the path with your own. On a VPS, add the same line to the web user\'s crontab.';
    }

    private function result(
        Status $status,
        Severity $severity,
        string $technicalReason,
        string $recommendedAction,
        string $adminAction,
        string $supportWording = '',
    ): CheckResult {
        return new CheckResult(
            key: $this->key(),
            title: $this->title(),
            category: $this->category(),
            status: $status,
            severity: $severity,
            responsibility: Responsibility::Hosting,
            technicalReason: $technicalReason,
            recommendedAction: $recommendedAction,
            adminAction: $adminAction,
            requiresHostingSupport: $supportWording !== '',
            supportWording: $supportWording,
        );
    }
}
