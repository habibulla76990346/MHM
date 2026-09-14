<?php

namespace Tests\Feature\Auth;

use App\Domains\Identity\Services\MfaService;
use App\Domains\Security\Services\PermissionRegistry;
use App\Http\Middleware\RequireMfaChallenge;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Optional MFA for privileged administrators (§23).
 *
 * THE INTERESTING TESTS ARE THE ONES ABOUT GETTING IT WRONG. Setting it up is
 * a form; what matters is that a session which has not answered the challenge
 * cannot reach anything, that a recovery code works exactly once, that
 * enforcement does not lock an administrator out of the page where they would
 * enrol, and that a customer is never asked at all.
 */
class MfaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function mfa(): MfaService
    {
        return app(MfaService::class);
    }

    private function user(string $role = PermissionRegistry::ADMIN): User
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'password' => Hash::make('a-very-long-passphrase-9!'),
        ]);
        $user->assignRole($role);

        return $user->fresh();
    }

    /** Enrol somebody properly, and hand back their recovery codes. */
    private function enrol(User $user): array
    {
        $secret = $this->mfa()->beginEnrolment($user);
        $codes = $this->mfa()->confirmEnrolment(
            $user->fresh(),
            app(Google2FA::class)->getCurrentOtp($secret),
        );

        return ['secret' => $secret, 'codes' => $codes];
    }

    // -- enrolment ------------------------------------------------------------------

    public function test_enrolment_needs_a_real_code_from_the_secret(): void
    {
        $user = $this->user();
        $secret = $this->mfa()->beginEnrolment($user);

        $this->assertNull($this->mfa()->confirmEnrolment($user->fresh(), '000000'),
            'A wrong code enrolled somebody.');
        $this->assertFalse($this->mfa()->isEnabledFor($user->fresh()));

        $codes = $this->mfa()->confirmEnrolment($user->fresh(), app(Google2FA::class)->getCurrentOtp($secret));

        $this->assertIsArray($codes);
        $this->assertCount(8, $codes);
        $this->assertTrue($this->mfa()->isEnabledFor($user->fresh()));
    }

    public function test_a_secret_that_was_never_confirmed_does_not_count_as_enabled(): void
    {
        // Somebody who scanned the code and closed the tab has a secret and no
        // working app. Treating that as enabled locks them out over an
        // unfinished form.
        $user = $this->user();
        $this->mfa()->beginEnrolment($user);

        $this->assertFalse($this->mfa()->isEnabledFor($user->fresh()));
    }

    public function test_the_secret_is_encrypted_at_rest_and_hidden_from_serialisation(): void
    {
        $user = $this->user();
        $secret = $this->mfa()->beginEnrolment($user);

        $raw = (string) DB::table('users')->where('id', $user->getKey())->value('mfa_secret');

        $this->assertNotSame($secret, $raw, 'The TOTP secret is stored in plain text.');
        // Anybody holding it can generate valid codes for ever.
        $this->assertArrayNotHasKey('mfa_secret', $user->fresh()->toArray());
        $this->assertArrayNotHasKey('mfa_recovery_codes', $user->fresh()->toArray());
    }

    public function test_recovery_codes_are_stored_as_hashes_not_as_codes(): void
    {
        $user = $this->user();
        ['codes' => $codes] = $this->enrol($user);

        $stored = (string) $user->fresh()->mfa_recovery_codes;

        foreach ($codes as $code) {
            $this->assertStringNotContainsString($code, $stored,
                'A recovery code is readable in the database.');
        }
    }

    // -- verification -----------------------------------------------------------------

    public function test_a_current_code_verifies_and_a_stale_one_does_not(): void
    {
        $user = $this->user();
        ['secret' => $secret] = $this->enrol($user);

        $this->assertTrue($this->mfa()->verify($user->fresh(), app(Google2FA::class)->getCurrentOtp($secret)));
        $this->assertFalse($this->mfa()->verify($user->fresh(), '123456'));
    }

    public function test_a_recovery_code_works_exactly_once(): void
    {
        $user = $this->user();
        ['codes' => $codes] = $this->enrol($user);
        $code = $codes[0];

        $this->assertTrue($this->mfa()->verify($user->fresh(), $code));
        // Consumed, not marked used: a used code left in the list with a flag
        // beside it is one refactor away from being accepted again.
        $this->assertFalse($this->mfa()->verify($user->fresh(), $code));
        $this->assertSame(7, $this->mfa()->remainingRecoveryCodes($user->fresh()));
    }

    public function test_regenerating_invalidates_the_old_set_immediately(): void
    {
        $user = $this->user();
        ['codes' => $old] = $this->enrol($user);

        $new = $this->mfa()->regenerateRecoveryCodes($user->fresh());

        $this->assertFalse($this->mfa()->verify($user->fresh(), $old[0]));
        $this->assertTrue($this->mfa()->verify($user->fresh(), $new[0]));
    }

    public function test_enabling_and_using_a_recovery_code_are_both_audited(): void
    {
        $user = $this->user();
        ['codes' => $codes] = $this->enrol($user);
        $this->mfa()->verify($user->fresh(), $codes[0]);

        $this->assertDatabaseHas('activity_logs', ['action' => 'security.mfa.enabled']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'security.mfa.recovery_code_used']);
    }

    // -- the challenge, over HTTP -------------------------------------------------------

    public function test_an_enrolled_user_reaches_nothing_until_they_answer(): void
    {
        $user = $this->user();
        $this->enrol($user);

        $this->actingAs($user->fresh())
            ->get(route('dashboard'))
            ->assertRedirect(route('mfa.challenge'));

        // Authentication and the second factor are separate events, so a
        // session stolen before the challenge is worth nothing.
        $this->actingAs($user->fresh())->get('/admin')->assertRedirect(route('mfa.challenge'));
    }

    public function test_answering_it_opens_the_application(): void
    {
        $user = $this->user();
        ['secret' => $secret] = $this->enrol($user);

        $this->actingAs($user->fresh())
            ->post(route('mfa.verify'), ['code' => app(Google2FA::class)->getCurrentOtp($secret)])
            ->assertRedirect(route('dashboard'));

        $this->assertTrue(session()->has(RequireMfaChallenge::PASSED));

        $this->actingAs($user->fresh())->get(route('dashboard'))->assertOk();
    }

    public function test_a_wrong_code_is_refused_and_the_session_stays_shut(): void
    {
        $user = $this->user();
        $this->enrol($user);

        $this->actingAs($user->fresh())
            ->post(route('mfa.verify'), ['code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->assertFalse(session()->has(RequireMfaChallenge::PASSED));
    }

    public function test_guessing_is_rate_limited_per_account(): void
    {
        $user = $this->user();
        $this->enrol($user);

        // Six digits have a million possibilities and a thirty-second life.
        // Without a limit that is brute-forceable inside the window.
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->actingAs($user->fresh())->post(route('mfa.verify'), ['code' => '000000']);
        }

        $response = $this->actingAs($user->fresh())->post(route('mfa.verify'), ['code' => '000000']);

        $response->assertSessionHasErrors('code');
        $this->assertStringContainsString('Too many attempts', collect(session('errors')->get('code'))->implode(' '));
    }

    // -- enforcement --------------------------------------------------------------------

    public function test_enforcement_sends_an_administrator_to_the_setup_page_not_to_a_wall(): void
    {
        settings()->set('auth.mfa_required_for_admins', true);
        $admin = $this->user(PermissionRegistry::ADMIN);

        // The point of the setting is to get everybody enrolled. A 403 they
        // cannot act on would be the opposite.
        $this->actingAs($admin)->get(route('dashboard'))->assertRedirect(route('mfa.setup'));
        $this->actingAs($admin)->get(route('mfa.setup'))->assertOk();
    }

    public function test_a_customer_is_never_asked_for_a_second_factor(): void
    {
        settings()->set('auth.mfa_required_for_admins', true);
        $customer = $this->user(PermissionRegistry::CUSTOMER);

        // Their account cannot change a price or read a credential, and a
        // customer forced through TOTP to read an invoice is one who leaves.
        $this->assertFalse($this->mfa()->isRequiredFor($customer));
        $this->actingAs($customer)->get(route('dashboard'))->assertOk();
    }

    public function test_it_cannot_be_switched_off_while_it_is_mandatory(): void
    {
        $admin = $this->user(PermissionRegistry::ADMIN);
        ['secret' => $secret] = $this->enrol($admin);

        settings()->set('auth.mfa_required_for_admins', true);

        $this->actingAs($admin->fresh())
            ->withSession([RequireMfaChallenge::PASSED => now()->toIso8601String()])
            ->post(route('mfa.disable'), ['password' => 'a-very-long-passphrase-9!'])
            ->assertSessionHasErrors('password');

        $this->assertTrue($this->mfa()->isEnabledFor($admin->fresh()));
    }

    public function test_switching_it_off_requires_the_password(): void
    {
        $admin = $this->user(PermissionRegistry::ADMIN);
        $this->enrol($admin);

        // The threat is a session somebody else is holding: without this, a
        // borrowed laptop removes the second factor in one click.
        $this->actingAs($admin->fresh())
            ->withSession([RequireMfaChallenge::PASSED => now()->toIso8601String()])
            ->post(route('mfa.disable'), ['password' => 'not-the-password'])
            ->assertSessionHasErrors('password');

        $this->assertTrue($this->mfa()->isEnabledFor($admin->fresh()));

        $this->actingAs($admin->fresh())
            ->withSession([RequireMfaChallenge::PASSED => now()->toIso8601String()])
            ->post(route('mfa.disable'), ['password' => 'a-very-long-passphrase-9!'])
            ->assertRedirect(route('account'));

        $this->assertFalse($this->mfa()->isEnabledFor($admin->fresh()));
        $this->assertDatabaseHas('activity_logs', ['action' => 'security.mfa.disabled']);
    }

    public function test_signing_out_is_always_reachable_from_the_challenge(): void
    {
        $user = $this->user();
        $this->enrol($user);

        // Otherwise somebody who has genuinely lost their device and their
        // codes cannot even leave the page.
        $this->actingAs($user->fresh())->post(route('logout'))->assertRedirect();
    }

    public function test_the_session_is_regenerated_when_the_challenge_is_answered(): void
    {
        $user = $this->user();
        ['secret' => $secret] = $this->enrol($user);

        $this->actingAs($user->fresh());
        $before = session()->getId();

        $this->post(route('mfa.verify'), ['code' => app(Google2FA::class)->getCurrentOtp($secret)]);

        // A session id an attacker already holds must not become an
        // authenticated one — the same reason it is regenerated on login.
        $this->assertNotSame($before, session()->getId());
    }
}
