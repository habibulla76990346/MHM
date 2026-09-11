<?php

namespace App\Domains\Diagnostics\Checks;

use App\Domains\Diagnostics\Support\Category;
use App\Domains\Diagnostics\Support\CheckResult;
use App\Domains\Diagnostics\Support\Responsibility;
use App\Domains\Diagnostics\Support\Severity;
use App\Domains\Diagnostics\Support\Status;

/** Informational context that every other finding is read against. */
class EnvironmentCheck extends BaseCheck
{
    public function key(): string { return 'environment.summary'; }
    public function title(): string { return 'Hosting environment'; }
    public function category(): Category { return Category::Environment; }

    public function run(): CheckResult
    {
        $mode = $this->mode();

        $facts = [
            'Deployment mode: '.$mode->label().(config('aziv.deployment_mode') === 'auto' ? ' (detected)' : ' (configured)'),
            'PHP '.PHP_VERSION.' via '.PHP_SAPI,
            'Server: '.($_SERVER['SERVER_SOFTWARE'] ?? 'unknown'),
            'Laravel '.app()->version(),
            'Cache: '.config('cache.default').' · Session: '.config('session.driver').' · Queue: '.config('queue.default'),
        ];

        return new CheckResult(
            key: $this->key(),
            title: $this->title(),
            category: $this->category(),
            status: Status::Green,
            severity: Severity::Informational,
            responsibility: Responsibility::Configuration,
            technicalReason: implode(' · ', $facts),
        );
    }
}
