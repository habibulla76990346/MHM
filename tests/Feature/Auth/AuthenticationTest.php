<?php

namespace Tests\Feature\Auth;

use App\Domains\Identity\Models\UserSession;
use App\Domains\Security\Models\ActivityLog;
use App\Domains\Security\Services\PermissionRegistry;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_a_visitor_can_register(): void
    {
        Event::fake([Registered::class]);

        $response = $this->post('/register', [
            'name' => 'Priya Sharma',
            'email' => 'priya@example.com',
            'password' => 'Correct-Horse-9922',
            'password_confirmation' => 'Correct-Horse-9922',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();

        $user = User::where('email', 'priya@example.com')->firstOrFail();
        $this->assertTrue($user->hasRole(PermissionRegistry::CUSTOMER));
        $this->assertNotNull($user->uuid, 'Every user needs a public UUID identifier.');
        $this->assertNotNull($user->profile, 'A profile row should be created alongside the account.');
        Event::assertDispatched(Registered::class);
    }

    public function test_registration_is_refused_when_the_setting_is_off(): void
    {
        settings()->set('auth.registration_enabled', false);

        $this->get('/register')->assertNotFound();
        $this->post('/register', [
            'name' => 'Blocked',
            'email' => 'blocked@example.com',
            'password' => 'Correct-Horse-9922',
            'password_confirmation' => 'Correct-Horse-9922',
        ])->assertNotFound();

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'blocked@example.com']);
    }

    public function test_the_password_policy_comes_from_settings(): void
    {
        settings()->set('auth.password_min_length', 20);

        $this->post('/register', [
            'name' => 'Short Password',
            'email' => 'short@example.com',
            'password' => 'Only-13-Chars',
            'password_confirmation' => 'Only-13-Chars',
        ])->assertSessionHasErrors('password');

        $this->assertDatabaseMissing('users', ['email' => 'short@example.com']);
    }

    public function test_a_user_can_sign_in(): void
    {
        $user = User::factory()->create(['password' => 'Correct-Horse-9922']);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'Correct-Horse-9922',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
        $this->assertDatabaseHas('activity_logs', ['action' => 'auth.login', 'actor_id' => $user->id]);
    }

    public function test_a_wrong_password_is_refused_and_logged(): void
    {
        $user = User::factory()->create(['password' => 'Correct-Horse-9922']);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertDatabaseHas('activity_logs', ['action' => 'auth.login_failed']);
    }

    /**
     * The error must not reveal whether the address exists — otherwise the
     * sign-in form becomes an account-enumeration oracle.
     */
    public function test_the_sign_in_error_does_not_reveal_whether_an_account_exists(): void
    {
        $user = User::factory()->create(['password' => 'Correct-Horse-9922']);

        $known = $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertSessionHasErrors('email');
        $unknown = $this->post('/login', ['email' => 'nobody@example.com', 'password' => 'wrong'])
            ->assertSessionHasErrors('email');

        $this->assertSame(
            session('errors')->get('email'),
            $unknown->getSession()->get('errors')->get('email'),
        );
    }

    /** A suspended account must not obtain a session, even with valid credentials. */
    public function test_a_suspended_account_cannot_sign_in(): void
    {
        $user = User::factory()->suspended()->create(['password' => 'Correct-Horse-9922']);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'Correct-Horse-9922',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertDatabaseHas('activity_logs', ['action' => 'auth.login_blocked_suspended']);
    }

    /** Suspension must end live sessions, not wait until the next sign-in. */
    public function test_suspending_a_signed_in_user_ends_their_session_on_the_next_request(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('dashboard'))->assertOk();

        $user->update(['status' => User::STATUS_SUSPENDED]);

        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_signing_out_revokes_the_session_record(): void
    {
        $user = User::factory()->create(['password' => 'Correct-Horse-9922']);

        $this->post('/login', ['email' => $user->email, 'password' => 'Correct-Horse-9922']);
        $this->assertDatabaseHas('user_sessions', ['user_id' => $user->id, 'revoked_at' => null]);

        $this->post('/logout')->assertRedirect(route('home'));
        $this->assertGuest();
        $this->assertSame(0, UserSession::where('user_id', $user->id)->whereNull('revoked_at')->count());
    }

    /** Oldest sessions are signed out first once the configured limit is passed. */
    public function test_the_concurrent_session_limit_revokes_the_oldest_sessions(): void
    {
        settings()->set('auth.max_concurrent_sessions', 2);
        $user = User::factory()->create();

        // Three sessions already open elsewhere, oldest first.
        foreach (['device-a', 'device-b', 'device-c'] as $i => $token) {
            UserSession::create([
                'user_id' => $user->id,
                'session_id' => $token,
                'last_activity_at' => now()->subMinutes(30 - $i),
            ]);
        }

        // Signing in on a fourth device goes through the real request cycle,
        // which is where the limit is applied.
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $active = UserSession::where('user_id', $user->id)->whereNull('revoked_at')->count();

        $this->assertSame(2, $active, 'Only the configured number of sessions may stay active.');
        $this->assertDatabaseHas('user_sessions', [
            'session_id' => 'device-a',
            'revoked_reason' => 'concurrency_limit',
        ]);
        // The session that just signed in is never the one revoked.
        $this->assertDatabaseMissing('user_sessions', [
            'session_id' => 'device-c',
            'revoked_reason' => 'concurrency_limit',
        ]);
    }

    public function test_a_password_reset_revokes_every_existing_session(): void
    {
        $user = User::factory()->create();
        UserSession::create(['user_id' => $user->id, 'session_id' => 'stale', 'last_activity_at' => now()]);

        $token = \Illuminate\Support\Facades\Password::createToken($user);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'Brand-New-Pass-4471',
            'password_confirmation' => 'Brand-New-Pass-4471',
        ])->assertRedirect(route('login'));

        $this->assertDatabaseHas('user_sessions', [
            'session_id' => 'stale',
            'revoked_reason' => 'password_reset',
        ]);
    }

    /** The reset endpoint must answer identically whether or not the address exists. */
    public function test_the_reset_request_does_not_reveal_whether_an_account_exists(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $known = $this->post('/forgot-password', ['email' => $user->email]);
        $unknown = $this->post('/forgot-password', ['email' => 'nobody@example.com']);

        $this->assertSame($known->getSession()->get('status'), $unknown->getSession()->get('status'));
    }

    public function test_an_unverified_user_is_held_at_the_verification_notice(): void
    {
        $user = User::factory()->unverified()->create(['status' => User::STATUS_ACTIVE]);

        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('verification.notice'));
    }

    public function test_maintenance_mode_blocks_visitors_but_not_administrators(): void
    {
        settings()->set('system.maintenance_mode', true);

        $this->get('/')->assertStatus(503);

        $admin = User::factory()->create();
        $admin->assignRole(PermissionRegistry::ADMIN);
        $this->actingAs($admin)->get('/')->assertOk();

        // Uptime monitoring must keep working during maintenance.
        $this->get('/up')->assertOk();
    }

    public function test_every_authentication_event_is_audit_logged(): void
    {
        $user = User::factory()->create(['password' => 'Correct-Horse-9922']);

        $this->post('/login', ['email' => $user->email, 'password' => 'Correct-Horse-9922']);
        $this->post('/logout');

        $actions = ActivityLog::pluck('action')->all();
        $this->assertContains('auth.login', $actions);
        $this->assertContains('auth.logout', $actions);
    }

    /** An audit trail that records a password defeats the point of hashing it. */
    public function test_the_audit_log_never_records_a_password(): void
    {
        $this->post('/register', [
            'name' => 'Audit Check',
            'email' => 'audit@example.com',
            'password' => 'Unique-Secret-8812',
            'password_confirmation' => 'Unique-Secret-8812',
        ]);

        $this->assertStringNotContainsString(
            'Unique-Secret-8812',
            ActivityLog::all()->toJson(),
            'A password reached the audit log.'
        );
    }
}
