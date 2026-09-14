<?php

namespace Tests\Feature\Deployment;

use App\Domains\Diagnostics\Services\MaintenanceService;
use App\Domains\Security\Services\PermissionRegistry;
use App\Filament\Pages\Maintenance;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The operations that would otherwise need SSH (Owner Addendum E §4).
 *
 * THE AUTHORITY IS THE POINT. Running migrations and rebuilding caches from a
 * web page is exactly what an attacker who obtained a support login would want
 * next, so `maintenance.run` is granted to a full administrator and to nobody
 * else — and `maintenance.view`, which is enough to read the recent log, is
 * granted separately.
 */
class MaintenanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole($role);

        return $user->fresh();
    }

    // -- who may do what -----------------------------------------------------------

    public function test_an_administrator_can_open_it_and_run_a_task(): void
    {
        Livewire::actingAs($this->user(PermissionRegistry::ADMIN))
            ->test(Maintenance::class)
            ->call('runTask', 'migrate')
            ->assertSet('lastOk', true);

        // Authorise, act, AUDIT — the third is what makes this accountable.
        $this->assertDatabaseHas('activity_logs', ['action' => 'maintenance.migrate']);
    }

    public function test_a_support_manager_may_read_the_log_and_run_nothing(): void
    {
        $support = $this->user(PermissionRegistry::SUPPORT_MANAGER);

        $this->assertTrue($support->can('maintenance.view'));
        $this->assertFalse($support->can('maintenance.run'));

        Livewire::actingAs($support)
            ->test(Maintenance::class)
            ->call('runTask', 'migrate')
            ->assertSet('output', '');

        $this->assertDatabaseMissing('activity_logs', ['action' => 'maintenance.migrate']);
    }

    public function test_a_finance_manager_has_no_maintenance_authority_at_all(): void
    {
        // Deny by default. A permission added in a later phase reaches no role
        // until it is granted there explicitly.
        $finance = $this->user(PermissionRegistry::FINANCE_MANAGER);

        $this->assertFalse($finance->can('maintenance.view'));
        $this->assertFalse($finance->can('maintenance.run'));
    }

    public function test_a_customer_cannot_reach_the_screen(): void
    {
        $customer = $this->user(PermissionRegistry::CUSTOMER);

        $this->actingAs($customer)->get('/admin/maintenance')->assertForbidden();
    }

    // -- it runs only what it offers --------------------------------------------------

    public function test_it_refuses_a_task_it_does_not_offer(): void
    {
        // A page that ran what it was given would be a shell with a web form
        // in front of it.
        Livewire::actingAs($this->user(PermissionRegistry::ADMIN))
            ->test(Maintenance::class)
            ->call('runTask', 'db:wipe')
            ->assertSet('output', '');

        $this->assertFalse(MaintenanceService::exists('db:wipe'));
        $this->assertFalse(MaintenanceService::exists('queue:flush'));
        $this->assertFalse(MaintenanceService::exists('migrate:fresh'));
    }

    /**
     * The SERVICE refuses too, not only the screen.
     *
     * The screen checks the allowlist before it calls the service, and that
     * check alone made this suite pass with the service's own guard deleted —
     * two guards, one tested. The service is the last thing before `artisan()`
     * and is reachable from anywhere: a future admin action, a console
     * command, a queued job. It has to refuse on its own.
     */
    public function test_the_service_refuses_an_unknown_task_even_when_called_directly(): void
    {
        foreach (['db:wipe', 'migrate:fresh', 'queue:flush', 'tinker', 'key:generate'] as $task) {
            $result = app(MaintenanceService::class)->run($task);

            $this->assertFalse($result['ok'], $task.' was accepted by the service.');
            $this->assertSame('Unknown task.', $result['output'],
                $task.' reached artisan — the maintenance screen is a shell with a web form in front of it.');
        }
    }

    public function test_nothing_destructive_is_on_the_list(): void
    {
        foreach (MaintenanceService::tasks() as $key => $task) {
            $this->assertFalse($task['destructive'], $key.' is offered and is destructive.');
            // A task whose artisan equivalent drops data has no business on a
            // screen with a single Run button and no confirmation.
            $this->assertStringNotContainsString('fresh', $task['equivalent']);
            $this->assertStringNotContainsString('wipe', $task['equivalent']);
        }
    }

    // -- output is safe to show ----------------------------------------------------------

    public function test_the_log_tail_is_scrubbed(): void
    {
        $path = storage_path('logs/laravel.log');
        @mkdir(dirname($path), 0755, true);
        file_put_contents(
            $path,
            "[2026-01-01 00:00:00] production.ERROR: Connection failed for mysql://root:hunter2secret@db/aziv\n"
            ."and the key sk-live-ABCDEFGHIJKLMNOPQRSTUV was rejected\n",
            FILE_APPEND,
        );

        $tail = app(MaintenanceService::class)->recentLog();

        // This screen is reachable by a Support Manager, and a stack trace
        // routinely carries a connection string.
        $this->assertStringNotContainsString('hunter2secret', $tail);
        $this->assertStringNotContainsString('sk-live-ABCDEFGHIJKLMNOPQRSTUV', $tail);
    }

    public function test_the_log_tail_reads_the_end_of_a_large_file_without_loading_it(): void
    {
        $path = storage_path('logs/laravel.log');
        @mkdir(dirname($path), 0755, true);

        // Five megabytes of noise, then the line that matters.
        file_put_contents($path, str_repeat("filler line that nobody needs to read\n", 140000));
        file_put_contents($path, "THE LAST LINE\n", FILE_APPEND);

        $tail = app(MaintenanceService::class)->recentLog(10);

        $this->assertStringContainsString('THE LAST LINE', $tail);
        // Proof it read the END rather than the file: ten lines, not 140,001.
        $this->assertLessThan(20, substr_count($tail, "\n"));

        @unlink($path);
    }

    // -- the report ---------------------------------------------------------------------

    public function test_the_health_report_downloads_and_is_audited(): void
    {
        $admin = $this->user(PermissionRegistry::ADMIN);

        $response = Livewire::actingAs($admin)
            ->test(Maintenance::class)
            ->call('downloadReport');

        $response->assertFileDownloaded();

        $this->assertDatabaseHas('activity_logs', ['action' => 'diagnostics.exported']);
    }

    public function test_somebody_without_the_export_permission_cannot_download_it(): void
    {
        $support = $this->user(PermissionRegistry::SUPPORT_MANAGER);

        $this->assertFalse($support->can('diagnostics.export'));

        Livewire::actingAs($support)
            ->test(Maintenance::class)
            ->call('downloadReport')
            ->assertForbidden();
    }
}
