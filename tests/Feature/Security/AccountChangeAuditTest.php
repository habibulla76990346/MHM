<?php

namespace Tests\Feature\Security;

use App\Domains\Security\Models\ActivityLog;
use App\Domains\Security\Services\PermissionRegistry;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Role and account-status changes leave a record (§23).
 *
 * ATTACHED TO THE MODEL AND THE FRAMEWORK'S OWN EVENTS, not to a screen —
 * there is no user-management resource in the panel yet, and when there is,
 * this already covers it, along with a console command, a seeder, and whatever
 * gets written next. An audit rule that lives on one screen ends the day a
 * second screen appears.
 */
class AccountChangeAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_suspending_an_account_is_recorded_with_what_it_was_before(): void
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        $user->forceFill(['status' => User::STATUS_SUSPENDED])->save();

        $entry = ActivityLog::where('action', 'user.status_changed')->latest('id')->firstOrFail();

        // "It is suspended" is a fact. "It was active and somebody changed it"
        // is a record.
        $this->assertSame(['status' => User::STATUS_ACTIVE], $entry->before);
        $this->assertSame(['status' => User::STATUS_SUSPENDED], $entry->after);
    }

    public function test_an_unrelated_change_to_an_account_is_not_recorded_as_a_status_change(): void
    {
        $user = User::factory()->create();

        $user->forceFill(['name' => 'A New Name'])->save();

        $this->assertSame(0, ActivityLog::where('action', 'user.status_changed')->count());
    }

    public function test_granting_an_administrative_role_is_recorded(): void
    {
        $user = User::factory()->create();

        $user->assignRole(PermissionRegistry::ADMIN);

        $entry = ActivityLog::where('action', 'user.role_granted')->latest('id')->firstOrFail();

        $this->assertContains(PermissionRegistry::ADMIN, $entry->after['roles']);
    }

    public function test_revoking_one_is_recorded_too(): void
    {
        $user = User::factory()->create();
        $user->assignRole(PermissionRegistry::ADMIN);

        $user->removeRole(PermissionRegistry::ADMIN);

        $entry = ActivityLog::where('action', 'user.role_revoked')->latest('id')->firstOrFail();

        // Losing an authority matters as much as gaining one: it is how
        // somebody covers a trail, and how an account stops working for a
        // reason nobody can explain.
        $this->assertContains(PermissionRegistry::ADMIN, $entry->after['roles']);
    }

    public function test_the_record_survives_the_actor_being_deleted(): void
    {
        $actor = User::factory()->create(['email' => 'the-admin@example.test']);
        $this->actingAs($actor);

        $target = User::factory()->create();
        $target->forceFill(['status' => User::STATUS_SUSPENDED])->save();

        $entry = ActivityLog::where('action', 'user.status_changed')->latest('id')->firstOrFail();

        $this->assertSame('the-admin@example.test', $entry->actor_label);

        $actor->delete();

        // The label is kept separately for exactly this reason.
        $this->assertSame('the-admin@example.test', $entry->fresh()->actor_label);
    }
}
