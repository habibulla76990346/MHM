<?php

namespace App\Domains\Diagnostics\Checks;

use App\Domains\Diagnostics\Support\Category;
use App\Domains\Diagnostics\Support\CheckResult;
use App\Domains\Diagnostics\Support\Responsibility;
use App\Domains\Diagnostics\Support\Severity;
use App\Domains\Diagnostics\Support\Status;

class FilesystemCheck extends BaseCheck
{
    public function key(): string { return 'filesystem.writable'; }
    public function title(): string { return 'Storage permissions'; }
    public function category(): Category { return Category::Filesystem; }

    public function run(): CheckResult
    {
        $paths = [
            storage_path('app'),
            storage_path('framework'),
            storage_path('logs'),
            base_path('bootstrap/cache'),
        ];

        $unwritable = [];
        foreach ($paths as $path) {
            if (! is_dir($path) || ! is_writable($path)) {
                $unwritable[] = str_replace(base_path().'/', '', $path);
                continue;
            }
            // is_writable() can lie under some ACL setups — prove it.
            $probe = $path.'/.aziv-write-probe';
            if (@file_put_contents($probe, '1') === false) {
                $unwritable[] = str_replace(base_path().'/', '', $path);
            } else {
                @unlink($probe);
            }
        }

        $ok = $unwritable === [];

        return new CheckResult(
            key: $this->key(),
            title: $ok ? $this->title() : 'Directories are not writable',
            category: $this->category(),
            status: $ok ? Status::Green : Status::Red,
            severity: $ok ? Severity::Informational : Severity::Critical,
            responsibility: Responsibility::Hosting,
            technicalReason: $ok
                ? 'All required directories are writable (verified by a real write).'
                : 'Not writable: '.implode(', ', $unwritable),
            recommendedAction: $ok ? '' : 'Aziv AI cannot write to these directories, so it cannot cache views, write logs or store uploads.',
            adminAction: $ok ? '' : 'Set permissions to 755 (or 775) on: '.implode(', ', $unwritable).'. In cPanel use File Manager → Permissions.',
            requiresHostingSupport: false,
        );
    }
}
