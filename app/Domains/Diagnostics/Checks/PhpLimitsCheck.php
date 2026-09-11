<?php

namespace App\Domains\Diagnostics\Checks;

use App\Domains\Diagnostics\Support\Category;
use App\Domains\Diagnostics\Support\CheckResult;
use App\Domains\Diagnostics\Support\DeploymentMode;
use App\Domains\Diagnostics\Support\Responsibility;
use App\Domains\Diagnostics\Support\Severity;
use App\Domains\Diagnostics\Support\Status;

class PhpLimitsCheck extends BaseCheck
{
    public function key(): string { return 'php.resource_limits'; }
    public function title(): string { return 'PHP resource limits'; }
    public function category(): Category { return Category::Php; }

    public function run(): CheckResult
    {
        $memory = $this->bytes(ini_get('memory_limit'));
        $upload = $this->bytes(ini_get('upload_max_filesize'));
        $post = $this->bytes(ini_get('post_max_size'));
        $exec = (int) ini_get('max_execution_time');

        $warnings = [];
        if ($memory > 0 && $memory < 128 * 1024 * 1024) {
            $warnings[] = 'memory_limit is '.ini_get('memory_limit').' (128M recommended)';
        }
        if ($upload > $post && $post > 0) {
            $warnings[] = 'upload_max_filesize ('.ini_get('upload_max_filesize').') exceeds post_max_size ('.ini_get('post_max_size').'), so larger uploads fail silently';
        }
        if ($exec > 0 && $exec < 60) {
            $warnings[] = 'max_execution_time is '.$exec.'s, which may cut off long AI requests';
        }

        // On shared hosting these limits are expected and largely unchangeable:
        // report them, but do not alarm. See Owner Addendum G §2.
        $isShared = $this->mode() === DeploymentMode::Shared;
        $status = $warnings === [] ? Status::Green : ($isShared ? Status::Yellow : Status::Yellow);

        return new CheckResult(
            key: $this->key(),
            title: $this->title(),
            category: $this->category(),
            status: $status,
            severity: $warnings === [] ? Severity::Informational : Severity::Low,
            responsibility: Responsibility::Hosting,
            technicalReason: sprintf(
                'memory_limit=%s · upload_max_filesize=%s · post_max_size=%s · max_execution_time=%s%s',
                ini_get('memory_limit'), ini_get('upload_max_filesize'),
                ini_get('post_max_size'), $exec === 0 ? 'unlimited' : $exec.'s',
                $warnings ? ' — '.implode('; ', $warnings) : ''
            ),
            recommendedAction: $warnings === [] ? '' : 'These limits constrain file uploads and long AI requests. Aziv AI adjusts its own caps to match, so nothing breaks — but raising them improves what users can do.',
            adminAction: $warnings === [] ? '' : 'In cPanel, "MultiPHP INI Editor" can raise these on most plans.',
        );
    }

    private function bytes(string|false $value): int
    {
        if ($value === false || $value === '') return 0;
        $value = trim($value);
        if ($value === '-1') return 0;
        $unit = strtolower($value[strlen($value) - 1]);
        $num = (int) $value;

        return match ($unit) {
            'g' => $num * 1024 ** 3,
            'm' => $num * 1024 ** 2,
            'k' => $num * 1024,
            default => $num,
        };
    }
}
