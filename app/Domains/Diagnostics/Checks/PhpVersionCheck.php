<?php

namespace App\Domains\Diagnostics\Checks;

use App\Domains\Diagnostics\Support\Category;
use App\Domains\Diagnostics\Support\CheckResult;
use App\Domains\Diagnostics\Support\Responsibility;
use App\Domains\Diagnostics\Support\Severity;
use App\Domains\Diagnostics\Support\Status;

class PhpVersionCheck extends BaseCheck
{
    public function key(): string { return 'php.version'; }
    public function title(): string { return 'PHP version'; }
    public function category(): Category { return Category::Php; }

    public function run(): CheckResult
    {
        $min = config('aziv.min_php_version', '8.3.0');
        $ok = version_compare(PHP_VERSION, $min, '>=');

        return new CheckResult(
            key: $this->key(),
            title: $this->title(),
            category: $this->category(),
            status: $ok ? Status::Green : Status::Red,
            severity: $ok ? Severity::Informational : Severity::Critical,
            responsibility: Responsibility::Hosting,
            technicalReason: sprintf('Running PHP %s; minimum supported is %s.', PHP_VERSION, $min),
            recommendedAction: $ok
                ? ''
                : 'This server runs a PHP version Aziv AI does not support. Aziv AI needs PHP '.$min.' or newer.',
            adminAction: $ok
                ? ''
                : 'In cPanel, open "Select PHP Version" (or MultiPHP Manager) and switch to PHP 8.3 or newer.',
            requiresHostingSupport: ! $ok,
            supportWording: $ok ? '' : 'Please switch my hosting account to PHP '.$min.' or newer.',
        );
    }
}
