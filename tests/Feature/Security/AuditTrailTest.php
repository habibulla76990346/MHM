<?php

namespace Tests\Feature\Security;

use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Models\PlanPrice;
use App\Domains\Billing\Services\SubscriptionService;
use App\Domains\Security\Models\ActivityLog;
use App\Domains\Security\Services\ActivityLogger;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * §23: "Activity logs for provider, credential, model, routing, subscription,
 * payment, role, theme and user-status changes."
 *
 * NAMED IN THE BLUEPRINT, so checked as a list rather than as a habit. Each of
 * these is an action where the question six months later is "who did this, and
 * what was it before?" — and an audit trail with a hole in it is worth less
 * than no audit trail, because it invites the conclusion that nothing happened.
 *
 * MOST OF IT ASSERTS THE ACTION EXISTS IN CODE, not that a particular screen
 * calls it. A test that drove every admin screen would be slow, brittle, and
 * would still only cover the screens that exist today.
 *
 * BUT A SOURCE SCAN CANNOT SEE A BROKEN WRITE. Deleting the body of the
 * subscription service's `audit()` helper — leaving every action string in
 * place and recording nothing — passed this suite. So the change §23 cares
 * about most, and the one this phase added, is also PERFORMED here and the
 * row is read back.
 */
class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every action string that reaches the audit log, found by reading the
     * files that write to it.
     *
     * PER FILE, not one concatenated blob, and only files that actually
     * reference `ActivityLogger`. A blob search would match an action name
     * mentioned in a comment or a test double and report coverage that does
     * not exist.
     *
     * @return array<int, string>
     */
    private function auditedActions(): array
    {
        static $actions = null;

        if ($actions !== null) {
            return $actions;
        }

        $actions = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            // Only somewhere that can actually write one.
            if (! str_contains($source, 'ActivityLogger')) {
                continue;
            }

            // Comments and docblocks stripped, so explaining an action cannot
            // be mistaken for recording it — the same reasoning as the
            // hard-coded-tax scan.
            $code = '';

            foreach (token_get_all($source) as $token) {
                if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $code .= is_array($token) ? $token[1] : $token;
            }

            preg_match_all("/'([a-z][a-z0-9_]*(?:\.[a-z0-9_]+)+)'/", $code, $matches);

            foreach ($matches[1] as $candidate) {
                $actions[] = $candidate;
            }
        }

        $actions = array_values(array_unique($actions));

        return $actions;
    }

    private function isAudited(string ...$prefixes): bool
    {
        foreach ($this->auditedActions() as $action) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($action, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function test_every_change_the_blueprint_names_is_logged_somewhere(): void
    {
        // The §23 list. Each has to be recorded by something that writes to
        // the audit log.
        $required = [
            'provider' => ['provider.', 'providers.'],
            'credential' => ['provider.credential', 'credential.', 'credentials.'],
            'model' => ['model.', 'models.'],
            'routing' => ['routing.'],
            'subscription' => ['subscription.', 'subscriptions.'],
            'payment' => ['payment.', 'payments.'],
            'role' => ['user.role_', 'role.', 'roles.'],
            'theme' => ['theme.', 'themes.', 'branding.'],
            'user status' => ['user.status', 'user.suspend', 'users.suspend'],
        ];

        $missing = [];

        foreach ($required as $what => $prefixes) {
            if (! $this->isAudited(...$prefixes)) {
                $missing[] = $what;
            }
        }

        $this->assertSame([], $missing,
            'The blueprint names these as audited changes and nothing logs them: '.implode(', ', $missing));
    }

    public function test_the_new_phase_nine_authorities_are_audited(): void
    {
        // Each of these is somebody reaching into the platform's operation or
        // into a customer's content, and each is new enough that the habit
        // could not have formed yet.
        foreach ([
            'maintenance.',                  // running migrations from a web page
            'media.settings_updated',        // switching image or voice on
            'media.image.deleted',           // reaching into a customer's pictures
            'security.mfa.enabled',          // adding a second factor
            'security.mfa.disabled',         // REMOVING one
            'diagnostics.exported',          // taking a report off the server
            'user.role_granted',             // making somebody an administrator
            'user.status_changed',           // suspending an account
            'subscription.plan_changed',     // moving somebody between plans
        ] as $action) {
            $this->assertTrue($this->isAudited($action), 'Nothing records "'.$action.'".');
        }
    }

    /**
     * A real plan change, and the row it must leave behind.
     *
     * The scan above proves the string exists; this proves something writes
     * it. Both are needed: the scan catches a service that never logged at
     * all, and this catches one that stopped.
     */
    public function test_a_real_subscription_change_leaves_a_real_record(): void
    {
        $user = User::factory()->create();

        $subscriptions = app(SubscriptionService::class);
        $small = $this->fixturePlan('Starter', 199);
        $large = $this->fixturePlan('Pro', 999);

        $subscription = $subscriptions->start($user, $small);

        $this->assertDatabaseHas('activity_logs', ['action' => 'subscription.started']);

        $subscriptions->changePlan($subscription->fresh(), $large, 'INR');

        $entry = ActivityLog::whereIn('action', ['subscription.plan_changed', 'subscription.change_scheduled'])
            ->latest('id')->first();

        $this->assertNotNull($entry, 'A customer moved between plans and nothing recorded it.');
        $this->assertSame($small->slug, $entry->before['plan'] ?? null,
            'The record does not say what the plan was before, which is the only thing that makes it a record.');
        $this->assertSame($large->slug, $entry->after['plan'] ?? null);
    }

    private function fixturePlan(string $name, float $price): Plan
    {
        $plan = Plan::create([
            'name' => $name,
            'billing_cycle' => 'monthly',
            'credits_per_period' => 100,
            'is_free' => false,
            'is_public' => true,
            'status' => Plan::STATUS_ACTIVE,
        ]);

        PlanPrice::create(['plan_id' => $plan->getKey(), 'currency' => 'INR', 'amount' => $price]);

        return $plan->fresh(['prices', 'features']);
    }

    public function test_an_audit_entry_records_who_what_and_the_before(): void
    {
        $log = app(ActivityLogger::class)
            ->log('fixture.change', null, ['status' => 'active'], ['status' => 'suspended']);

        $stored = ActivityLog::findOrFail($log->getKey());

        $this->assertSame('fixture.change', $stored->action);
        // BEFORE and AFTER, not just after: "the status is suspended" is a
        // fact, and "it was active and somebody changed it" is a record.
        $this->assertSame(['status' => 'active'], $stored->before);
        $this->assertSame(['status' => 'suspended'], $stored->after);
        // Kept separately, so the trail survives the actor being deleted.
        $this->assertNotNull($stored->actor_label);
    }

    public function test_an_audit_entry_never_stores_a_credential(): void
    {
        $log = app(ActivityLogger::class)->log(
            'fixture.credential',
            null,
            ['credential' => 'sk-live-ABCDEFGHIJKLMNOPQRSTUV'],
            ['credential' => 'sk-live-ZZZZZZZZZZZZZZZZZZZZZZ'],
        );

        $encoded = json_encode(ActivityLog::findOrFail($log->getKey())->only(['before', 'after']));

        $this->assertStringNotContainsString('sk-live-ABCDEFGHIJKLMNOPQRSTUV', (string) $encoded);
        $this->assertStringNotContainsString('sk-live-ZZZZZZZZZZZZZZZZZZZZZZ', (string) $encoded);
    }
}
