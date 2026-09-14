<?php

namespace App\Domains\Diagnostics\Checks;

use App\Domains\Diagnostics\Support\Category;
use App\Domains\Diagnostics\Support\CheckResult;
use App\Domains\Diagnostics\Support\Responsibility;
use App\Domains\Diagnostics\Support\Severity;
use App\Domains\Diagnostics\Support\Status;

/**
 * How much room is left, and how fast it is going (Phase 9).
 *
 * A FULL DISK IS THE FAILURE THAT BREAKS EVERYTHING AT ONCE and explains
 * nothing. Uploads fail, generated images fail, the session store fails, the
 * log cannot record why, and MySQL stops accepting writes — so the diagnostics
 * page itself may be the last thing that still renders. It is worth warning
 * about early and loudly.
 *
 * IT REPORTS WHAT THE PLATFORM IS USING TOO, because on shared hosting the
 * filesystem figure is often the whole server rather than the account's quota,
 * and "you have 400GB free" is useless to somebody with a 5GB plan. The one
 * number that is always true and always ours is how much Aziv AI has written.
 */
class StorageSpaceCheck extends BaseCheck
{
    private const WARN_PERCENT = 85;

    private const CRITICAL_PERCENT = 95;

    /** Below this, percentages stop meaning anything useful. */
    private const CRITICAL_FREE_BYTES = 512 * 1024 * 1024;

    public function key(): string
    {
        return 'filesystem.space';
    }

    public function title(): string
    {
        return 'Disk space';
    }

    public function category(): Category
    {
        return Category::Filesystem;
    }

    public function run(): CheckResult
    {
        $path = storage_path('app');
        $free = @disk_free_space($path);
        $total = @disk_total_space($path);
        $used = $this->directorySize(storage_path('app'));

        $ours = 'Aziv AI is using '.$this->human($used).'.';

        if ($free === false || $total === false || $total <= 0) {
            // Several shared hosts disable these. Not a fault — say what can
            // be known rather than reporting a fabricated zero.
            return $this->result(
                Status::Grey,
                Severity::Informational,
                'This server does not report disk space to PHP. '.$ours,
                'Check your hosting control panel for the account quota.',
                'Nothing to do here — use your host\'s own disk usage screen.',
            );
        }

        $percentUsed = (int) round((($total - $free) / $total) * 100);
        $facts = $percentUsed.'% of the filesystem is used, '.$this->human((int) $free).' free. '.$ours;

        if ($percentUsed >= self::CRITICAL_PERCENT || $free < self::CRITICAL_FREE_BYTES) {
            return $this->result(
                Status::Red,
                Severity::Critical,
                $facts,
                'When this fills, uploads fail, generated images fail, sessions fail and the database stops accepting writes — all at once, and the log cannot record why.',
                'Free space now. Admin → Media → Images and voice can shorten how long generated files are kept; old logs in storage/logs can be deleted.',
                requiresHostingSupport: true,
                supportWording: 'My hosting account is nearly out of disk space. Please advise on increasing the quota.',
            );
        }

        if ($percentUsed >= self::WARN_PERCENT) {
            return $this->result(
                Status::Yellow,
                Severity::High,
                $facts,
                'There is room now, and not much of it.',
                'Set a retention period for generated images and recordings on Admin → Media, or ask your host about more space.',
            );
        }

        return $this->result(
            Status::Green,
            Severity::Informational,
            $facts,
            'There is room to grow.',
            'Nothing to do.',
        );
    }

    /**
     * Bytes under a directory.
     *
     * BOUNDED, because this runs on a page an administrator opens: a walk of
     * a huge upload tree would turn a health check into a timeout. Past the
     * cap it stops and reports what it counted, which is enough to see a
     * trend.
     */
    private function directorySize(string $directory, int $maxFiles = 20000): int
    {
        if (! is_dir($directory)) {
            return 0;
        }

        $bytes = 0;
        $seen = 0;

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY,
            );

            foreach ($iterator as $file) {
                if (++$seen > $maxFiles) {
                    break;
                }

                if ($file->isFile()) {
                    $bytes += $file->getSize();
                }
            }
        } catch (\Throwable) {
            return $bytes;
        }

        return $bytes;
    }

    private function human(int $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $unit) {
            if ($bytes < 1024 || $unit === 'TB') {
                return round($bytes, $unit === 'B' ? 0 : 1).' '.$unit;
            }

            $bytes = (int) ($bytes / 1024);
        }

        return $bytes.' B';
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
            responsibility: Responsibility::Hosting,
            technicalReason: $technicalReason,
            recommendedAction: $recommendedAction,
            adminAction: $adminAction,
            requiresHostingSupport: $requiresHostingSupport,
            supportWording: $supportWording,
        );
    }
}
