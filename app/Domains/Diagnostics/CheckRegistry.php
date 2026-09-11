<?php

namespace App\Domains\Diagnostics;

use App\Domains\Diagnostics\Contracts\DiagnosticCheck;
use App\Domains\Diagnostics\Support\CheckResult;
use App\Domains\Diagnostics\Support\Category;
use App\Domains\Diagnostics\Support\Responsibility;
use App\Domains\Diagnostics\Support\Severity;
use App\Domains\Diagnostics\Support\Status;

/**
 * The extensible registry (Owner Addendum G §5).
 *
 * New modules and providers register their own checks here, so an AI adapter
 * added in Phase 7 or a payment gateway added in Phase 6 arrives WITH
 * diagnostics rather than leaving a coverage gap.
 */
class CheckRegistry
{
    /** @var array<string, DiagnosticCheck> */
    private array $checks = [];

    public function register(DiagnosticCheck $check): void
    {
        $this->checks[$check->key()] = $check;
    }

    /** @param iterable<DiagnosticCheck> $checks */
    public function registerMany(iterable $checks): void
    {
        foreach ($checks as $check) {
            $this->register($check);
        }
    }

    /** @return array<string, DiagnosticCheck> */
    public function all(): array
    {
        return $this->checks;
    }

    public function get(string $key): ?DiagnosticCheck
    {
        return $this->checks[$key] ?? null;
    }

    /**
     * @param  bool  $automaticOnly  Exclude checks that cost money or have
     *                               side effects — used by scheduled runs.
     * @return array<int, CheckResult>
     */
    public function run(bool $automaticOnly = false): array
    {
        $results = [];

        foreach ($this->checks as $check) {
            if ($automaticOnly && (! $check->isSafeToRunAutomatically() || $check->hasSideEffects() || $check->costsMoney())) {
                continue;
            }

            $results[] = $this->runOne($check);
        }

        usort($results, fn (CheckResult $a, CheckResult $b) => [$a->severity->rank(), $a->category->value]
            <=> [$b->severity->rank(), $b->category->value]);

        return $results;
    }

    public function runOne(DiagnosticCheck $check): CheckResult
    {
        if (! $check->isApplicable()) {
            return new CheckResult(
                key: $check->key(),
                title: $check->title(),
                category: $check->category(),
                status: Status::Grey,
                severity: Severity::Informational,
                responsibility: Responsibility::Configuration,
                technicalReason: 'Not applicable in this environment.',
            );
        }

        $start = microtime(true);

        try {
            return $check->run();
        } catch (\Throwable $e) {
            // A check that throws is a bug in the check, not a finding about
            // the server — report it as such rather than as a false alarm.
            return new CheckResult(
                key: $check->key(),
                title: $check->title().' (check failed to run)',
                category: $check->category(),
                status: Status::Grey,
                severity: Severity::Low,
                responsibility: Responsibility::Application,
                technicalReason: 'The diagnostic itself errored: '.class_basename($e).' — '.$e->getMessage(),
                recommendedAction: 'This is a defect in Aziv AI’s diagnostics, not a problem with your server.',
                durationMs: (microtime(true) - $start) * 1000,
            );
        }
    }
}
