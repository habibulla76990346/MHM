<?php

namespace Tests\Unit\Diagnostics;

use App\Domains\Diagnostics\Support\Category;
use App\Domains\Diagnostics\Support\CheckResult;
use App\Domains\Diagnostics\Support\Responsibility;
use App\Domains\Diagnostics\Support\Severity;
use App\Domains\Diagnostics\Support\Status;
use PHPUnit\Framework\TestCase;

class CheckResultTest extends TestCase
{
    /**
     * "Secret-free by construction" means no output path can emit an
     * unredacted value — not that the edges filter it. If this test fails,
     * the console, the JSON output and the exported report are all exposed.
     */
    public function test_technical_reason_is_scrubbed_at_construction(): void
    {
        $result = new CheckResult(
            key: 'test.check',
            title: 'Test',
            category: Category::Database,
            status: Status::Red,
            severity: Severity::Critical,
            responsibility: Responsibility::Database,
            technicalReason: 'failed for mysql://user:sup3rs3cret@host/db',
        );

        $this->assertStringNotContainsString('sup3rs3cret', $result->technicalReason);
        $this->assertStringNotContainsString('sup3rs3cret', json_encode($result->toArray()));
    }

    public function test_severity_orders_critical_first(): void
    {
        $ranks = array_map(fn (Severity $s) => $s->rank(), [
            Severity::Critical, Severity::High, Severity::Medium,
            Severity::Low, Severity::Informational,
        ]);

        $sorted = $ranks;
        sort($sorted);
        $this->assertSame($sorted, $ranks, 'Severity ranks must ascend from Critical.');
    }

    public function test_only_red_counts_as_a_problem(): void
    {
        $this->assertTrue(Status::Red->isProblem());
        // GREY must NOT be a problem: on shared hosting an absent Redis is
        // expected, and permanent false alarms train admins to ignore red.
        $this->assertFalse(Status::Grey->isProblem());
        $this->assertFalse(Status::Yellow->isProblem());
        $this->assertFalse(Status::Green->isProblem());
    }
}
