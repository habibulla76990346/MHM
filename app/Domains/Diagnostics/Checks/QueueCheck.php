<?php

namespace App\Domains\Diagnostics\Checks;

use App\Domains\Diagnostics\Support\Category;
use App\Domains\Diagnostics\Support\CheckResult;
use App\Domains\Diagnostics\Support\Responsibility;
use App\Domains\Diagnostics\Support\Severity;
use App\Domains\Diagnostics\Support\Status;
use App\Jobs\VerifyQueueJob;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Is anything actually processing queued work? (Owner Addendum G, Phase 9.)
 *
 * EVERY LONG OPERATION IN THIS PLATFORM IS A QUEUED JOB — indexing a document,
 * generating a picture, transcribing a recording, sending every email. If
 * nothing drains the queue, none of it happens and none of it errors: the
 * customer sees "Making…" for ever and the owner sees a growing table.
 *
 * TWO DIFFERENT QUESTIONS, and the distinction matters for what to tell the
 * owner. A BACKLOG means work is arriving faster than it leaves — more
 * capacity. An OLD job at the head of the queue means nothing is draining it
 * at all — a stopped worker, or a cron line that was never added. The second
 * is an outage; the first is a Tuesday.
 */
class QueueCheck extends BaseCheck
{
    /** A backlog this deep is worth mentioning. */
    private const BUSY_JOBS = 100;

    /** A job waiting this long means nothing is draining the queue. */
    private const STUCK_MINUTES = 30;

    public function key(): string
    {
        return 'queue.processing';
    }

    public function title(): string
    {
        return 'Background jobs';
    }

    public function category(): Category
    {
        return Category::QueueCron;
    }

    public function run(): CheckResult
    {
        $driver = (string) config('queue.default');

        if ($driver === 'sync') {
            // Not a fault, and not healthy either: work runs inside the
            // request, so a slow provider becomes a slow page and a failure
            // becomes an error the customer sees.
            return $this->result(
                Status::Yellow,
                Severity::Medium,
                'The queue driver is "sync", so jobs run inside the web request.',
                'Indexing, image generation and email all happen while somebody waits, and a slow provider becomes a timeout they see.',
                'Set QUEUE_CONNECTION=database in .env and add the scheduler cron job.',
            );
        }

        if ($driver !== 'database') {
            // Redis and the rest: the depth query below is written for the
            // database driver, so say what is configured rather than guess.
            return $this->result(
                Status::Green,
                Severity::Informational,
                'Queue driver is "'.$driver.'".',
                'Depth is reported by that driver\'s own tooling rather than here.',
                'Nothing to do.',
            );
        }

        try {
            $pending = DB::table('jobs')->count();
            $oldest = DB::table('jobs')->min('available_at');
            $failed = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();
        } catch (Throwable) {
            return $this->result(
                Status::Grey,
                Severity::Informational,
                'The queue tables are not present.',
                'Run the migrations, or switch QUEUE_CONNECTION to a driver that does not need them.',
                'Open Admin → Maintenance and run pending migrations.',
            );
        }

        $waitMinutes = $oldest ? (int) Carbon::createFromTimestamp((int) $oldest)->diffInMinutes(now()) : 0;
        $verified = Cache::get(VerifyQueueJob::CACHE_KEY);

        if ($pending > 0 && $waitMinutes >= self::STUCK_MINUTES) {
            return $this->result(
                Status::Red,
                Severity::Critical,
                $pending.' job(s) waiting, the oldest for '.$waitMinutes.' minutes.',
                'Nothing is draining the queue. Documents will not index, images will not generate and no email will be sent.',
                'Check the scheduler cron job first — on shared hosting it is what runs the queue. On a VPS, check the worker.',
                requiresHostingSupport: true,
                supportWording: 'My scheduled task or queue worker appears to have stopped.',
            );
        }

        if ($pending >= self::BUSY_JOBS) {
            return $this->result(
                Status::Yellow,
                Severity::Medium,
                $pending.' job(s) waiting; the oldest has waited '.$waitMinutes.' minute(s).',
                'Work is arriving faster than it is being processed. Things still happen, later than they should.',
                'On shared hosting this is expected under load. On a VPS, run more queue workers.',
            );
        }

        if ($failed > 0) {
            return $this->result(
                Status::Yellow,
                Severity::High,
                $failed.' job(s) failed in the last 24 hours.',
                'Something a customer asked for did not happen. The reason is in the failed_jobs table.',
                'Open Admin → Maintenance to see recent log entries, then retry or clear the failures.',
            );
        }

        return $this->result(
            Status::Green,
            Severity::Informational,
            $pending.' job(s) waiting.'.($verified ? ' Last verified drain: '.($verified['at'] ?? 'unknown').'.' : ''),
            'Background work is being processed.',
            'Nothing to do.',
        );
    }

    private function result(
        Status $status,
        Severity $severity,
        string $technicalReason,
        string $recommendedAction,
        string $adminAction,
        bool $requiresHostingSupport = false,
        string $supportWording = '',
    ): CheckResult {
        return new CheckResult(
            key: $this->key(),
            title: $this->title(),
            category: $this->category(),
            status: $status,
            severity: $severity,
            responsibility: Responsibility::Configuration,
            technicalReason: $technicalReason,
            recommendedAction: $recommendedAction,
            adminAction: $adminAction,
            requiresHostingSupport: $requiresHostingSupport,
            supportWording: $supportWording,
        );
    }
}
