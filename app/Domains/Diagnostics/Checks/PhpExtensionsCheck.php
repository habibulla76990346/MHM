<?php

namespace App\Domains\Diagnostics\Checks;

use App\Domains\Diagnostics\Support\Category;
use App\Domains\Diagnostics\Support\CheckResult;
use App\Domains\Diagnostics\Support\Responsibility;
use App\Domains\Diagnostics\Support\Severity;
use App\Domains\Diagnostics\Support\Status;

class PhpExtensionsCheck extends BaseCheck
{
    public function key(): string { return 'php.extensions'; }
    public function title(): string { return 'Required PHP extensions'; }
    public function category(): Category { return Category::Php; }

    public function run(): CheckResult
    {
        $required = config('aziv.required_extensions', []);
        $missing = array_values(array_filter($required, fn ($e) => ! $this->loaded($e)));

        $optional = config('aziv.optional_extensions', []);
        $missingOptional = array_keys(array_filter($optional, fn ($why, $e) => ! $this->loaded($e), ARRAY_FILTER_USE_BOTH));

        $ok = $missing === [];

        $reason = $ok
            ? sprintf('All %d required extensions present.', count($required))
            : sprintf('Missing required extension%s: %s.', count($missing) === 1 ? '' : 's', implode(', ', $missing));

        if ($missingOptional !== []) {
            $reason .= ' Optional not installed: '.implode(', ', $missingOptional).'.';
        }

        return new CheckResult(
            key: $this->key(),
            title: $ok ? $this->title() : 'PHP extension missing: '.implode(', ', $missing),
            category: $this->category(),
            status: $ok ? Status::Green : Status::Red,
            severity: $ok ? Severity::Informational : Severity::High,
            responsibility: Responsibility::Hosting,
            technicalReason: $reason,
            recommendedAction: $ok ? '' : 'Aziv AI needs these PHP extensions to run correctly.',
            adminAction: $ok ? '' : 'In cPanel, open "Select PHP Version" → Extensions and enable: '.implode(', ', $missing).'. If an extension is not listed, your hosting provider must enable it.',
            requiresHostingSupport: ! $ok,
            supportWording: $ok ? '' : 'Please enable the following PHP extensions on my account: '.implode(', ', $missing).'.',
        );
    }

    /**
     * extension_loaded() is case-sensitive-ish and some extensions register
     * under a display name that differs from the ini name — OPcache is
     * "Zend OPcache", not "opcache". Reporting a present extension as missing
     * is exactly the kind of false alarm that teaches an admin to distrust
     * the health screen, so resolve aliases explicitly.
     */
    private function loaded(string $name): bool
    {
        if (extension_loaded($name)) {
            return true;
        }

        return match (strtolower($name)) {
            'opcache' => extension_loaded('Zend OPcache') || function_exists('opcache_get_status'),
            'imagick' => class_exists(\Imagick::class),
            default => false,
        };
    }
}
