<?php

namespace Tests\Feature\Diagnostics;

use App\Domains\AI\Models\AiProvider;
use App\Domains\AI\Models\AiProviderCredential;
use App\Domains\Diagnostics\Checks\ApplicationStateCheck;
use App\Domains\Diagnostics\Checks\ExposureCheck;
use App\Domains\Diagnostics\Checks\QueueCheck;
use App\Domains\Diagnostics\Checks\SchedulerCheck;
use App\Domains\Diagnostics\Checks\StorageSpaceCheck;
use App\Domains\Diagnostics\Support\Status;
use App\Models\User;
use App\Support\Installer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Phase 9 operational checks (Owner Addendum G).
 *
 * EVERY ONE OF THESE EXISTS FOR A FAULT THAT LOOKS LIKE NOTHING. Cron never
 * added, a queue nothing drains, a migration that was not run after an update,
 * a disk quietly filling, and `.env` served as a URL. In each case the site
 * keeps working and the damage accumulates out of sight, which is exactly the
 * shape of problem a health screen is for.
 */
class OperationalChecksTest extends TestCase
{
    use RefreshDatabase;

    // -- the scheduler ----------------------------------------------------------

    public function test_a_scheduler_that_has_never_run_is_critical(): void
    {
        Cache::forget(SchedulerCheck::HEARTBEAT_KEY);

        $result = app(SchedulerCheck::class)->run();

        $this->assertSame(Status::Red, $result->status);
        // The cron line itself, because the owner cannot write one from a
        // description.
        $this->assertStringContainsString('schedule:run', $result->adminAction);
        $this->assertTrue($result->requiresHostingSupport);
    }

    public function test_a_scheduler_that_ran_a_moment_ago_is_green(): void
    {
        Cache::put(SchedulerCheck::HEARTBEAT_KEY, now()->toIso8601String(), now()->addDay());

        $this->assertSame(Status::Green, app(SchedulerCheck::class)->run()->status);
    }

    public function test_a_late_scheduler_is_a_warning_and_a_stopped_one_is_not(): void
    {
        // One missed tick on a busy server is not news. Three hours of
        // silence is an outage.
        Cache::put(SchedulerCheck::HEARTBEAT_KEY, now()->subMinutes(45)->toIso8601String(), now()->addDay());
        $this->assertSame(Status::Yellow, app(SchedulerCheck::class)->run()->status);

        Cache::put(SchedulerCheck::HEARTBEAT_KEY, now()->subHours(5)->toIso8601String(), now()->addDay());
        $this->assertSame(Status::Red, app(SchedulerCheck::class)->run()->status);
    }

    // -- the queue ---------------------------------------------------------------

    public function test_an_empty_queue_is_green(): void
    {
        config(['queue.default' => 'database']);

        $this->assertSame(Status::Green, app(QueueCheck::class)->run()->status);
    }

    public function test_a_job_nobody_has_touched_for_half_an_hour_is_an_outage(): void
    {
        config(['queue.default' => 'database']);

        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->subHour()->timestamp,
            'created_at' => now()->subHour()->timestamp,
        ]);

        $result = app(QueueCheck::class)->run();

        $this->assertSame(Status::Red, $result->status);
        // The remedy points at cron first, because on shared hosting that IS
        // the queue.
        $this->assertStringContainsString('scheduler', $result->adminAction);
    }

    public function test_the_sync_driver_is_reported_as_a_compromise_rather_than_health(): void
    {
        config(['queue.default' => 'sync']);

        $result = app(QueueCheck::class)->run();

        $this->assertSame(Status::Yellow, $result->status);
        $this->assertStringContainsString('inside the web request', $result->technicalReason);
    }

    // -- application state --------------------------------------------------------

    public function test_a_migrated_database_with_readable_credentials_is_green(): void
    {
        $this->assertSame(Status::Green, app(ApplicationStateCheck::class)->run()->status);
    }

    public function test_a_credential_that_no_longer_decrypts_is_critical(): void
    {
        $provider = AiProvider::create([
            'name' => 'Somebody',
            'adapter_type' => 'openai_compatible',
            'api_base_url' => 'https://api.example.test/v1',
            'status' => 'active',
        ]);

        AiProviderCredential::create([
            'provider_id' => $provider->getKey(),
            'label' => 'Key',
            'credential' => 'sk-fixture-KEYKEYKEYKEY1234',
        ]);

        // Exactly what a restore beside a freshly generated APP_KEY leaves
        // behind: ciphertext nothing on this server can read.
        DB::table('ai_provider_credentials')->update([
            'credential' => 'eyJpdiI6ImJvZ3VzIiwidmFsdWUiOiJib2d1cyIsIm1hYyI6ImJvZ3VzIn0=',
        ]);

        $result = app(ApplicationStateCheck::class)->run();

        $this->assertSame(Status::Red, $result->status);
        $this->assertStringContainsString('APP_KEY', $result->technicalReason);
        // And it says the one thing that must not be got wrong next.
        $this->assertStringContainsString('ORIGINAL APP_KEY', $result->adminAction);
    }

    // -- disk ---------------------------------------------------------------------

    public function test_disk_space_reports_what_the_platform_is_using(): void
    {
        $result = app(StorageSpaceCheck::class)->run();

        $this->assertContains($result->status, [Status::Green, Status::Yellow, Status::Red, Status::Grey]);
        $this->assertStringContainsString('Aziv AI is using', $result->technicalReason);
    }

    // -- exposure -------------------------------------------------------------------

    public function test_exposure_is_not_graded_against_a_local_address(): void
    {
        config(['app.url' => 'http://localhost']);

        $this->assertSame(Status::Grey, app(ExposureCheck::class)->run()->status);
    }

    public function test_a_reachable_dotenv_is_the_worst_finding_the_platform_can_make(): void
    {
        config(['app.url' => 'https://aziv.example']);

        Http::fake([
            'https://aziv.example/.env' => Http::response("APP_KEY=base64:xxx\nDB_PASSWORD=secret", 200),
            '*' => Http::response('', 404),
        ]);

        $result = app(ExposureCheck::class)->run();

        $this->assertSame(Status::Red, $result->status);
        $this->assertStringContainsString('/.env', $result->technicalReason);
        // The remedy is the document root, and it says so before it says
        // anything else.
        $this->assertStringContainsString('public/', $result->adminAction);
        $this->assertTrue($result->requiresHostingSupport);
    }

    public function test_the_finding_about_a_leaked_dotenv_does_not_itself_leak_it(): void
    {
        config(['app.url' => 'https://aziv.example']);

        Http::fake([
            'https://aziv.example/.env' => Http::response('DB_PASSWORD=hunter2secret', 200),
            '*' => Http::response('', 404),
        ]);

        $result = app(ExposureCheck::class)->run();

        $this->assertStringNotContainsString('hunter2secret', $result->technicalReason);
    }

    public function test_an_open_installer_is_critical(): void
    {
        config(['app.url' => 'https://aziv.example']);
        Http::fake(['*' => Http::response('', 404)]);

        // No users and no lock file: a fresh install, so the installer is
        // open. On a live server that is a full compromise in two clicks.
        User::query()->delete();
        @unlink(app(Installer::class)->lockPath());

        $result = app(ExposureCheck::class)->run();

        $this->assertSame(Status::Red, $result->status);
        $this->assertStringContainsString('installer', strtolower($result->technicalReason));
    }

    public function test_a_clean_server_reports_green(): void
    {
        config(['app.url' => 'https://aziv.example']);
        Http::fake(['*' => Http::response('', 404)]);

        User::factory()->create();   // installed: there are accounts

        $this->assertSame(Status::Green, app(ExposureCheck::class)->run()->status);
    }
}
