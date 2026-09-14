<?php

namespace Tests\Feature\Deployment;

use App\Domains\Security\Services\PermissionRegistry;
use App\Models\User;
use App\Support\Install\EnvWriter;
use App\Support\Installer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The browser installer, and the thing that makes it survivable (Addendum E §4).
 *
 * AN OPEN INSTALLER ON A LIVE SITE IS A FULL COMPROMISE: it can rewrite `.env`
 * and create an administrator. Everything in this suite is about the closing,
 * not the installing — because the installing is a form and the closing is the
 * security control.
 *
 * `Installer::isOpen()` needs THREE conditions to agree, and the important one
 * is the third: whether the database already holds accounts. A lock file can
 * be deleted by anybody with file access, and a switch can be flipped back;
 * the presence of real users cannot be arranged by an attacker who is trying
 * to get in.
 */
class InstallerTest extends TestCase
{
    use RefreshDatabase;

    private function installer(): Installer
    {
        return app(Installer::class);
    }

    /** A genuinely fresh system: no accounts, no lock. */
    private function fresh(): void
    {
        User::query()->forceDelete();
        @unlink($this->installer()->lockPath());
    }

    protected function tearDown(): void
    {
        @unlink($this->installer()->lockPath());

        parent::tearDown();
    }

    // -- when it may run ---------------------------------------------------------

    public function test_a_fresh_system_can_reach_the_installer(): void
    {
        $this->fresh();

        $this->get('/install')->assertOk()->assertSee('What this server can do');
    }

    public function test_a_system_with_accounts_has_no_installer_at_all(): void
    {
        $this->fresh();
        User::factory()->create();

        // The condition an attacker cannot arrange. Not a 403 — that would
        // confirm the route exists, and the existence of an installer is
        // itself worth knowing if you are looking for a way in.
        foreach (['/install', '/install/database', '/install/application', '/install/run', '/install/administrator'] as $path) {
            $this->get($path)->assertNotFound();
        }

        $this->post('/install/administrator', [])->assertNotFound();
    }

    public function test_a_lock_file_closes_it_even_with_an_empty_database(): void
    {
        $this->fresh();
        $this->installer()->lock();

        $this->get('/install')->assertNotFound();
    }

    public function test_the_switch_closes_it_before_anything_else(): void
    {
        $this->fresh();

        // An owner who will never use it can turn it off before deploying and
        // never think about it again.
        $installer = new class extends Installer
        {
            public function isEnabled(): bool
            {
                return false;
            }
        };
        $this->app->instance(Installer::class, $installer);

        $this->assertFalse($installer->isOpen());
        $this->get('/install')->assertNotFound();
    }

    public function test_deleting_the_lock_does_not_reopen_an_installed_system(): void
    {
        $this->fresh();
        User::factory()->create();
        $this->installer()->lock();

        @unlink($this->installer()->lockPath());

        $this->assertTrue($this->installer()->isInstalled(), 'The database still has accounts.');
        $this->get('/install')->assertNotFound();
    }

    // -- the walk-through ---------------------------------------------------------

    public function test_it_creates_the_first_administrator_and_locks_itself(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->fresh();

        $response = $this->post('/install/administrator', [
            'name' => 'The Owner',
            'email' => 'owner@example.test',
            'password' => 'A-very-long-passphrase-9!',
            'password_confirmation' => 'A-very-long-passphrase-9!',
        ]);

        $response->assertRedirect(route('install.finished'));

        $owner = User::where('email', 'owner@example.test')->firstOrFail();

        $this->assertTrue($owner->hasRole(PermissionRegistry::SUPER_ADMIN));
        $this->assertNotNull($owner->email_verified_at);

        // Locked the moment the account exists.
        $this->assertTrue($this->installer()->isLocked());
        $this->get('/install')->assertNotFound();
    }

    public function test_the_success_page_is_visible_only_to_the_browser_that_installed(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->fresh();

        $this->post('/install/administrator', [
            'name' => 'The Owner',
            'email' => 'owner@example.test',
            'password' => 'A-very-long-passphrase-9!',
            'password_confirmation' => 'A-very-long-passphrase-9!',
        ]);

        // Same session: the page that tells them what to do next.
        $this->get(route('install.finished'))->assertOk()->assertSee('Aziv AI is installed');

        // Anybody else: nothing here.
        $this->flushSession();
        $this->get(route('install.finished'))->assertNotFound();
    }

    public function test_a_second_administrator_cannot_be_created_through_it(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->fresh();

        User::factory()->create();

        $this->post('/install/administrator', [
            'name' => 'Intruder',
            'email' => 'intruder@example.test',
            'password' => 'A-very-long-passphrase-9!',
            'password_confirmation' => 'A-very-long-passphrase-9!',
        ])->assertNotFound();

        $this->assertDatabaseMissing('users', ['email' => 'intruder@example.test']);
    }

    public function test_a_weak_administrator_password_is_refused(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->fresh();

        $this->post('/install/administrator', [
            'name' => 'The Owner',
            'email' => 'owner@example.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasErrors('password');

        $this->assertSame(0, User::count());
    }

    // -- nothing entered is ever echoed --------------------------------------------

    public function test_a_failed_database_step_never_repeats_the_password_back(): void
    {
        $this->fresh();

        $response = $this->post('/install/database', [
            'host' => '127.0.0.1',
            'port' => 3307,               // nothing listening
            'database' => 'nope',
            'username' => 'nobody',
            'password' => 'hunter2-the-real-one',
        ]);

        $response->assertSessionHasErrors('database');

        // Not in the flashed input, not in the error message, not anywhere the
        // next page could render it.
        $this->assertArrayNotHasKey('password', (array) session('_old_input'));

        $errors = collect(session('errors')->all())->implode(' ');
        $this->assertStringNotContainsString('hunter2-the-real-one', $errors);
    }

    public function test_the_database_step_is_rate_limited(): void
    {
        $this->fresh();

        // Without a limit this is an oracle for guessing a database password
        // on any host where the database is reachable from the web server.
        $route = collect(app('router')->getRoutes())->first(fn ($r) => $r->getName() === 'install.database.test');

        $this->assertContains('throttle:10,1', $route->gatherMiddleware());
    }

    // -- the env writer ---------------------------------------------------------------

    public function test_the_env_writer_replaces_values_and_keeps_everything_else(): void
    {
        $path = sys_get_temp_dir().'/aziv-env-test-'.uniqid();
        file_put_contents($path, "# A comment worth keeping\nAPP_NAME=Old\n\nUNRELATED=leave-me\n");

        (new EnvWriter($path))->write(['APP_NAME' => 'New Name', 'BRAND_NEW' => 'yes']);

        $written = (string) file_get_contents($path);

        $this->assertStringContainsString('# A comment worth keeping', $written);
        $this->assertStringContainsString('UNRELATED=leave-me', $written);
        $this->assertStringContainsString('APP_NAME="New Name"', $written);
        $this->assertStringContainsString('BRAND_NEW=yes', $written);
        $this->assertStringNotContainsString('APP_NAME=Old', $written);

        unlink($path);
    }

    public function test_a_password_with_awkward_characters_is_quoted(): void
    {
        // A space, a hash and a dollar each break dotenv parsing in a
        // different way, and the symptom is "connection refused" with a
        // perfectly correct password.
        $path = sys_get_temp_dir().'/aziv-env-test-'.uniqid();
        file_put_contents($path, "DB_PASSWORD=\n");

        (new EnvWriter($path))->write(['DB_PASSWORD' => 'a b#c$d"e']);

        $this->assertStringContainsString('DB_PASSWORD="a b#c$d\"e"', (string) file_get_contents($path));

        unlink($path);
    }

    public function test_the_env_file_is_written_owner_only(): void
    {
        $path = sys_get_temp_dir().'/aziv-env-test-'.uniqid();
        file_put_contents($path, "APP_NAME=x\n");

        (new EnvWriter($path))->write(['APP_NAME' => 'y']);

        // It is every credential on the server, and a default umask on shared
        // hosting is routinely group-readable.
        $this->assertSame('0600', substr(sprintf('%o', fileperms($path)), -4));

        unlink($path);
    }
}
