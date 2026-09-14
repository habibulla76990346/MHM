<?php

namespace Tests\Feature\Security;

use App\Domains\Security\Models\ActivityLog;
use App\Domains\Security\Services\PermissionRegistry;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Locking yourself out must not be the end of the platform (delivery guide 11).
 *
 * There is deliberately no emailed back door and no master password — anything
 * that could let the owner back in could let somebody else in. The recovery
 * route is therefore the shell, where the person already owns the server, and
 * these tests pin the two things that make that safe: it refuses accounts it
 * has no business touching, and it never records the password it set.
 */
class AdminRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function administrator(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole(PermissionRegistry::SUPER_ADMIN);

        return $user->fresh();
    }

    public function test_it_resets_an_administrators_password(): void
    {
        $admin = $this->administrator();
        $before = $admin->password;

        $this->artisan('aziv:admin:reset', ['--email' => $admin->email])
            ->expectsQuestion('New password (input hidden)', 'A-brand-new-passphrase-9')
            ->expectsQuestion('Repeat it', 'A-brand-new-passphrase-9')
            ->assertExitCode(0);

        $admin->refresh();

        $this->assertNotSame($before, $admin->password);
        $this->assertTrue(Hash::check('A-brand-new-passphrase-9', $admin->password));
    }

    public function test_it_refuses_when_the_two_entries_disagree(): void
    {
        $admin = $this->administrator();
        $before = $admin->password;

        $this->artisan('aziv:admin:reset', ['--email' => $admin->email])
            ->expectsQuestion('New password (input hidden)', 'A-brand-new-passphrase-9')
            ->expectsQuestion('Repeat it', 'A-brand-new-passphrase-8')
            ->assertExitCode(1);

        $this->assertSame($before, $admin->fresh()->password,
            'A mistyped confirmation changed the password anyway.');
    }

    public function test_it_refuses_a_password_that_would_be_rejected_on_the_form(): void
    {
        $admin = $this->administrator();
        $before = $admin->password;

        $this->artisan('aziv:admin:reset', ['--email' => $admin->email])
            ->expectsQuestion('New password (input hidden)', 'password')
            ->expectsQuestion('Repeat it', 'password')
            ->assertExitCode(1);

        $this->assertSame($before, $admin->fresh()->password,
            'The console is a way round the password policy.');
    }

    public function test_it_refuses_an_account_that_is_not_an_administrator(): void
    {
        $customer = User::factory()->create();
        $customer->assignRole(PermissionRegistry::CUSTOMER);
        $before = $customer->fresh()->password;

        $this->artisan('aziv:admin:reset', ['--email' => $customer->email])->assertExitCode(1);

        $this->assertSame($before, $customer->fresh()->password);
    }

    public function test_it_refuses_an_address_that_does_not_exist(): void
    {
        $this->artisan('aziv:admin:reset', ['--email' => 'nobody@example.test'])->assertExitCode(1);
    }

    public function test_it_clears_two_factor_only_when_asked_and_confirmed(): void
    {
        $admin = $this->administrator([
            'mfa_secret' => 'JBSWY3DPEHPK3PXP',
            'mfa_confirmed_at' => now(),
            'mfa_recovery_codes' => Hash::make('abcd-efgh'),
        ]);

        // Without the flag, a plain password reset leaves it alone — a
        // forgotten password and a lost phone are different accidents.
        $this->artisan('aziv:admin:reset', ['--email' => $admin->email])
            ->expectsQuestion('New password (input hidden)', 'A-brand-new-passphrase-9')
            ->expectsQuestion('Repeat it', 'A-brand-new-passphrase-9')
            ->assertExitCode(0);

        $this->assertNotNull($admin->fresh()->mfa_confirmed_at);

        $this->artisan('aziv:admin:reset', ['--email' => $admin->email, '--clear-mfa' => true])
            ->expectsConfirmation('Remove two-factor authentication from this account?', 'yes')
            ->expectsConfirmation('Set a new password as well?', 'no')
            ->assertExitCode(0);

        $admin->refresh();

        $this->assertNull($admin->mfa_confirmed_at);
        $this->assertNull($admin->mfa_secret);
    }

    public function test_it_unlocks_a_suspended_administrator(): void
    {
        $admin = $this->administrator(['status' => User::STATUS_SUSPENDED]);

        $this->artisan('aziv:admin:reset', ['--email' => $admin->email, '--unlock' => true])
            ->expectsConfirmation('Set a new password as well?', 'no')
            ->assertExitCode(0);

        $this->assertSame(User::STATUS_ACTIVE, $admin->fresh()->status);
    }

    public function test_the_new_password_is_never_written_to_the_audit_log(): void
    {
        $admin = $this->administrator();

        $this->artisan('aziv:admin:reset', ['--email' => $admin->email])
            ->expectsQuestion('New password (input hidden)', 'A-brand-new-passphrase-9')
            ->expectsQuestion('Repeat it', 'A-brand-new-passphrase-9')
            ->assertExitCode(0);

        $entry = ActivityLog::where('action', 'admin.password_reset')->latest('id')->first();

        $this->assertNotNull($entry, 'A password reset was not recorded at all.');

        // The whole row, not just the fields we happen to remember: an audit
        // table is read by more people than an inbox is.
        $this->assertStringNotContainsString('A-brand-new-passphrase-9', json_encode($entry->toArray()),
            'The password an administrator was given is sitting in the audit log.');
    }
}
