<?php

namespace Tests\Feature\Diagnostics;

use App\Domains\Diagnostics\CheckRegistry;
use App\Domains\Diagnostics\Checks\BaseCheck;
use App\Domains\Diagnostics\Support\Category;
use App\Domains\Diagnostics\Support\CheckResult;
use App\Domains\Diagnostics\Support\Responsibility;
use App\Domains\Diagnostics\Support\Severity;
use App\Domains\Diagnostics\Support\Status;
use Tests\TestCase;

class DiagnosticsRunTest extends TestCase
{
    public function test_the_phase_zero_checks_are_registered(): void
    {
        $keys = array_keys(app(CheckRegistry::class)->all());

        foreach ([
            'environment.summary', 'php.version', 'php.extensions',
            'php.resource_limits', 'filesystem.writable',
            'database.connection', 'network.outbound_https',
        ] as $expected) {
            $this->assertContains($expected, $keys);
        }
    }

    public function test_every_finding_carries_the_required_fields(): void
    {
        $registry = app(CheckRegistry::class);
        $result = $registry->runOne($registry->get('php.version'));
        $array = $result->toArray();

        // Owner Addendum G §4: eleven required fields on every finding.
        foreach ([
            'key', 'title', 'category', 'severity', 'responsibility',
            'technical_reason', 'recommended_action', 'admin_action',
            'requires_hosting_support', 'log_reference', 'checked_at',
        ] as $field) {
            $this->assertArrayHasKey($field, $array, "Finding is missing required field [{$field}].");
        }
    }

    public function test_a_check_that_throws_is_reported_as_a_defect_not_a_false_alarm(): void
    {
        $registry = new CheckRegistry();
        $registry->register(new class extends BaseCheck {
            public function key(): string { return 'test.explodes'; }
            public function title(): string { return 'Exploding check'; }
            public function category(): Category { return Category::Application; }
            public function run(): CheckResult { throw new \RuntimeException('boom'); }
        });

        $result = $registry->run()[0];

        // A broken check must not masquerade as a broken server.
        $this->assertSame(Status::Grey, $result->status);
        $this->assertSame(Responsibility::Application, $result->responsibility);
        $this->assertStringContainsString('diagnostics', strtolower($result->recommendedAction));
    }

    public function test_automatic_runs_exclude_checks_that_cost_money_or_have_side_effects(): void
    {
        $registry = new CheckRegistry();
        $registry->register(new class extends BaseCheck {
            public function key(): string { return 'test.expensive'; }
            public function title(): string { return 'Costs money'; }
            public function category(): Category { return Category::AiProviders; }
            public function costsMoney(): bool { return true; }
            public function run(): CheckResult
            {
                return CheckResult::pass('test.expensive', 'Costs money', Category::AiProviders, Responsibility::Provider);
            }
        });
        $registry->register(new class extends BaseCheck {
            public function key(): string { return 'test.sends_mail'; }
            public function title(): string { return 'Sends mail'; }
            public function category(): Category { return Category::Mail; }
            public function hasSideEffects(): bool { return true; }
            public function run(): CheckResult
            {
                return CheckResult::pass('test.sends_mail', 'Sends mail', Category::Mail, Responsibility::Configuration);
            }
        });

        $this->assertCount(2, $registry->run(automaticOnly: false));
        $this->assertCount(0, $registry->run(automaticOnly: true),
            'Scheduled runs must never cost money or send real email.');
    }

    public function test_results_are_ordered_with_the_most_severe_first(): void
    {
        $results = app(CheckRegistry::class)->run();
        $ranks = array_map(fn (CheckResult $r) => $r->severity->rank(), $results);
        $sorted = $ranks;
        sort($sorted);

        $this->assertSame($sorted, $ranks);
    }
}
