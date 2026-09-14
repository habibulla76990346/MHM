<?php

namespace Tests\Feature\Diagnostics;

use App\Domains\Diagnostics\CheckRegistry;
use App\Domains\Diagnostics\Checks\BaseCheck;
use App\Domains\Diagnostics\Models\DiagnosticBaseline;
use App\Domains\Diagnostics\Models\DiagnosticResult;
use App\Domains\Diagnostics\Models\DiagnosticRun;
use App\Domains\Diagnostics\Services\DiagnosticRunner;
use App\Domains\Diagnostics\Support\Category;
use App\Domains\Diagnostics\Support\CheckResult;
use App\Domains\Diagnostics\Support\Responsibility;
use App\Domains\Diagnostics\Support\Severity;
use App\Domains\Diagnostics\Support\Status;
use App\Domains\Notifications\Models\NotificationDelivery;
use App\Domains\Notifications\Services\Notifier;
use App\Domains\Notifications\Support\NotificationEvent;
use App\Domains\Security\Services\PermissionRegistry;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Diagnostic history and transition alerting (Owner Addendum G §7, Phase 9).
 *
 * THE RULE THIS SUITE DEFENDS is that an owner is told when something CHANGES
 * and left alone while it stays the same. Alerting on every red sends the
 * identical message every night until it is fixed, and the predictable result
 * is a filter rule — after which the one that mattered is missed too.
 */
class DiagnosticHistoryTest extends TestCase
{
    use RefreshDatabase;

    /** What the fake check should report on the next run. */
    private static Status $status = Status::Green;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        self::$status = Status::Green;

        // ONE check, entirely under this test's control. Running the real
        // catalogue would make every assertion depend on the machine.
        $registry = new CheckRegistry;
        $registry->register(new class extends BaseCheck
        {
            public function key(): string
            {
                return 'fixture.check';
            }

            public function title(): string
            {
                return 'Fixture check';
            }

            public function category(): Category
            {
                return Category::Application;
            }

            public function run(): CheckResult
            {
                return new CheckResult(
                    key: $this->key(),
                    title: $this->title(),
                    category: $this->category(),
                    status: DiagnosticHistoryTest::fixtureStatus(),
                    severity: Severity::High,
                    responsibility: Responsibility::Application,
                    technicalReason: 'The fixture says '.DiagnosticHistoryTest::fixtureStatus()->value.'.',
                    adminAction: 'Do the fixture thing.',
                );
            }
        });

        $this->app->instance(CheckRegistry::class, $registry);
    }

    public static function fixtureStatus(): Status
    {
        return self::$status;
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->assignRole(PermissionRegistry::ADMIN);

        return $admin->fresh();
    }

    private function runner(): DiagnosticRunner
    {
        return app(DiagnosticRunner::class);
    }

    // -- history ----------------------------------------------------------------

    public function test_a_run_is_recorded_with_its_findings(): void
    {
        $outcome = $this->runner()->run(DiagnosticRun::MANUAL);

        $this->assertSame(1, DiagnosticRun::count());
        $this->assertSame(1, DiagnosticResult::count());

        $run = $outcome['run'];

        $this->assertSame('green', $run->overall_status);
        $this->assertSame(1, $run->green);
        $this->assertNotNull($run->finished_at);
    }

    public function test_the_worst_finding_decides_the_run(): void
    {
        self::$status = Status::Red;

        $this->assertSame('red', $this->runner()->run()['run']->overall_status);
    }

    public function test_nothing_written_to_history_carries_a_credential(): void
    {
        // Scrubbed at construction by CheckResult, so there is no path into
        // this table that could store an unredacted value — but the table is
        // read by more people than a log is, so it is asserted rather than
        // assumed.
        $registry = new CheckRegistry;
        $registry->register(new class extends BaseCheck
        {
            public function key(): string
            {
                return 'leaky.check';
            }

            public function title(): string
            {
                return 'Leaky check';
            }

            public function category(): Category
            {
                return Category::Security;
            }

            public function run(): CheckResult
            {
                return new CheckResult(
                    key: $this->key(),
                    title: $this->title(),
                    category: $this->category(),
                    status: Status::Red,
                    severity: Severity::Critical,
                    responsibility: Responsibility::Configuration,
                    technicalReason: 'Connection failed for sk-live-ABCDEFGHIJKLMNOPQRSTUV and MAIL_PASSWORD=hunter2secret',
                );
            }
        });
        $this->app->instance(CheckRegistry::class, $registry);

        $this->runner()->run();

        $stored = (string) DiagnosticResult::first()->technical_reason;

        $this->assertStringNotContainsString('sk-live-ABCDEFGHIJKLMNOPQRSTUV', $stored);
        $this->assertStringNotContainsString('hunter2secret', $stored);
        $this->assertStringContainsString('[redacted]', $stored);
    }

    // -- transitions ------------------------------------------------------------

    public function test_the_first_run_announces_nothing(): void
    {
        $this->admin();
        self::$status = Status::Red;

        $outcome = $this->runner()->run();

        // Nothing CHANGED — there was nothing to change from. Announcing the
        // state of a server the first time it is looked at would email an
        // owner about problems they have not created yet.
        $this->assertSame([], $outcome['transitions']);
        $this->assertSame(0, NotificationDelivery::where('event_key', NotificationEvent::DIAGNOSTIC_CHANGED)->count());
    }

    public function test_breaking_is_announced_once_and_not_again(): void
    {
        $this->admin();

        $this->runner()->run();          // green — establishes the baseline
        self::$status = Status::Red;

        $first = $this->runner()->run();
        $this->assertCount(1, $first['transitions']);

        $sent = NotificationDelivery::where('event_key', NotificationEvent::DIAGNOSTIC_CHANGED)->count();
        $this->assertGreaterThan(0, $sent);

        // Still red. Nothing has changed, so nothing is said.
        $second = $this->runner()->run();

        $this->assertSame([], $second['transitions']);
        $this->assertSame($sent, NotificationDelivery::where('event_key', NotificationEvent::DIAGNOSTIC_CHANGED)->count(),
            'The same problem was announced twice.');
    }

    public function test_recovering_is_announced_too(): void
    {
        $this->admin();

        $this->runner()->run();
        self::$status = Status::Red;
        $this->runner()->run();

        self::$status = Status::Green;
        $recovery = $this->runner()->run();

        $this->assertCount(1, $recovery['transitions']);
        $this->assertTrue($recovery['transitions'][0]['recovered']);
    }

    public function test_a_change_that_is_not_a_change_of_health_is_not_announced(): void
    {
        $this->admin();

        self::$status = Status::Red;
        $this->runner()->run();

        // Red to yellow is still broken. Worth recording, not worth an email.
        self::$status = Status::Yellow;
        $outcome = $this->runner()->run();

        $this->assertSame([], $outcome['transitions']);
        $this->assertSame('yellow', DiagnosticBaseline::first()->last_status);
    }

    public function test_grey_counts_as_healthy_because_not_applicable_is_an_answer(): void
    {
        $this->admin();

        $this->runner()->run();
        self::$status = Status::Grey;

        // A feature switched off makes its check inapplicable. Emailing about
        // that would mean an email every time an administrator turns
        // something off.
        $this->assertSame([], $this->runner()->run()['transitions']);
    }

    public function test_how_long_it_has_been_broken_is_counted(): void
    {
        self::$status = Status::Red;

        $this->runner()->run();
        $this->runner()->run();
        $this->runner()->run();

        $this->assertSame(3, (int) DiagnosticBaseline::first()->consecutive_failures);
    }

    public function test_recovery_resets_the_count(): void
    {
        self::$status = Status::Red;
        $this->runner()->run();

        self::$status = Status::Green;
        $this->runner()->run();

        $this->assertSame(0, (int) DiagnosticBaseline::first()->consecutive_failures);
    }

    // -- scheduled runs are narrower --------------------------------------------

    public function test_a_scheduled_run_skips_anything_that_costs_money(): void
    {
        $registry = new CheckRegistry;
        $registry->register(new class extends BaseCheck
        {
            public function key(): string
            {
                return 'expensive.check';
            }

            public function title(): string
            {
                return 'Expensive check';
            }

            public function category(): Category
            {
                return Category::AiProviders;
            }

            public function costsMoney(): bool
            {
                return true;
            }

            public function run(): CheckResult
            {
                throw new \RuntimeException('A scheduled run spent the owner\'s money.');
            }
        });
        $this->app->instance(CheckRegistry::class, $registry);

        $outcome = $this->runner()->run(DiagnosticRun::SCHEDULED);

        $this->assertSame([], $outcome['results']);
        $this->assertSame(0, DiagnosticResult::count());
    }

    public function test_a_manual_run_includes_it(): void
    {
        $registry = new CheckRegistry;
        $registry->register(new class extends BaseCheck
        {
            public function key(): string
            {
                return 'expensive.check';
            }

            public function title(): string
            {
                return 'Expensive check';
            }

            public function category(): Category
            {
                return Category::AiProviders;
            }

            public function costsMoney(): bool
            {
                return true;
            }

            public function run(): CheckResult
            {
                return CheckResult::pass($this->key(), $this->title(), Category::AiProviders, Responsibility::Configuration, 'Reached it.');
            }
        });
        $this->app->instance(CheckRegistry::class, $registry);

        $this->assertCount(1, $this->runner()->run(DiagnosticRun::MANUAL)['results']);
    }

    public function test_a_run_survives_the_mail_server_being_the_thing_that_is_broken(): void
    {
        $this->admin();
        $this->runner()->run();

        // The notifier throwing must not take the run down with it: a
        // diagnostics run that dies because mail is broken hides the finding
        // that says mail is broken.
        $this->app->bind(Notifier::class, fn () => new class extends Notifier
        {
            public function send(User $user, string $eventKey, array $values = [], ?Model $reference = null, ?array $onlyChannels = null): array
            {
                throw new \RuntimeException('SMTP is down.');
            }
        });

        self::$status = Status::Red;

        $outcome = $this->runner()->run();

        $this->assertCount(1, $outcome['transitions']);
        $this->assertSame(2, DiagnosticRun::count(), 'The run was not recorded.');
    }
}
