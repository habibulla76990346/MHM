<?php

namespace Tests\Feature\Billing;

use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Models\PlanPrice;
use App\Domains\Billing\Models\Subscription;
use App\Domains\Billing\Models\SubscriptionPeriod;
use App\Domains\Billing\Services\SubscriptionService;
use App\Domains\Credits\Services\CreditService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Phase 6 gate for subscriptions: a period cannot be activated twice,
 * upgrades and downgrades prorate correctly, and cancelling is not a refund.
 */
class SubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private SubscriptionService $subscriptions;

    private CreditService $credits;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->subscriptions = app(SubscriptionService::class);
        $this->credits = app(CreditService::class);
    }

    private function plan(string $name, float $price, float $credits, bool $default = false, bool $rollover = false): Plan
    {
        $plan = Plan::create([
            'name' => $name,
            'billing_cycle' => 'monthly',
            'credits_per_period' => $credits,
            'credits_rollover' => $rollover,
            'is_default' => $default,
            'is_free' => $price <= 0,
            'is_public' => true,
            'status' => Plan::STATUS_ACTIVE,
        ]);

        if ($price > 0) {
            PlanPrice::create(['plan_id' => $plan->getKey(), 'currency' => 'INR', 'amount' => $price]);
        }

        return $plan->fresh(['prices', 'features']);
    }

    // -- the activation guard -------------------------------------------------

    public function test_a_period_cannot_be_activated_twice(): void
    {
        $plan = $this->plan('Pro', 999, 500);
        $subscription = $this->subscriptions->start($this->user, $plan);

        $start = $subscription->current_period_start;
        $end = $subscription->current_period_end;

        // The same webhook, delivered again. It must grant nothing.
        $second = $this->subscriptions->activatePeriod($subscription, $start, $end, 'webhook:retry');

        $this->assertNull($second);
        $this->assertSame(1, SubscriptionPeriod::where('subscription_id', $subscription->getKey())->count());
        $this->assertEqualsWithDelta(500.0, (float) $this->credits->balance($this->user)->confirmed_balance, 0.000001);
    }

    public function test_a_new_period_grants_again(): void
    {
        $plan = $this->plan('Pro', 999, 500, rollover: true);
        $subscription = $this->subscriptions->start($this->user, $plan);

        $this->subscriptions->renew($subscription);

        $this->assertSame(2, SubscriptionPeriod::where('subscription_id', $subscription->getKey())->count());
        $this->assertEqualsWithDelta(1000.0, (float) $this->credits->balance($this->user)->confirmed_balance, 0.000001);
    }

    public function test_an_allowance_that_does_not_roll_over_is_cleared_before_the_next_grant(): void
    {
        $plan = $this->plan('Pro', 999, 500, rollover: false);
        $subscription = $this->subscriptions->start($this->user, $plan);

        // They used 100 of the first 500.
        $hold = $this->credits->hold($this->user, 100);
        $this->credits->settle($hold, 100);

        $this->subscriptions->renew($subscription);

        // Not 900: the unused 400 did not carry over, because the owner said
        // it should not.
        $this->assertEqualsWithDelta(500.0, (float) $this->credits->balance($this->user)->fresh()->confirmed_balance, 0.000001);
        $this->assertTrue($this->credits->reconciles($this->user));
    }

    // -- everyone is on a plan ------------------------------------------------

    public function test_an_existing_account_lands_on_the_default_plan_the_first_time_it_is_used(): void
    {
        $free = $this->plan('Free', 0, 50, default: true);

        $subscription = $this->subscriptions->ensureSubscription($this->user);

        $this->assertNotNull($subscription);
        $this->assertSame($free->getKey(), $subscription->plan_id);
        $this->assertEqualsWithDelta(50.0, (float) $this->credits->balance($this->user)->confirmed_balance, 0.000001);
    }

    public function test_asking_twice_does_not_create_a_second_subscription(): void
    {
        $this->plan('Free', 0, 50, default: true);

        $first = $this->subscriptions->ensureSubscription($this->user);
        $second = $this->subscriptions->ensureSubscription($this->user);

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(1, Subscription::where('user_id', $this->user->getKey())->count());
    }

    public function test_with_no_default_plan_nobody_is_forced_onto_one(): void
    {
        // An owner who has published nothing has a working platform, not one
        // where every customer is blocked by a subscription system they never
        // configured.
        $this->assertNull($this->subscriptions->ensureSubscription($this->user));
    }

    public function test_only_one_plan_can_be_the_default(): void
    {
        $first = $this->plan('Free', 0, 50, default: true);
        $second = $this->plan('Starter', 0, 80, default: true);

        $this->assertFalse($first->fresh()->is_default);
        $this->assertTrue($second->fresh()->is_default);
        $this->assertSame($second->getKey(), Plan::default()->getKey());
    }

    // -- proration ------------------------------------------------------------

    public function test_an_upgrade_charges_the_difference_and_takes_effect_now(): void
    {
        $pro = $this->plan('Pro', 1000, 500);
        $premium = $this->plan('Premium', 3000, 2000);

        $subscription = $this->subscriptions->start($this->user, $pro);

        // Halfway through the month.
        $this->travelTo($subscription->current_period_start->copy()->addDays(15));

        $proration = $this->subscriptions->changePlan($subscription->fresh(), $premium, 'INR');

        $this->assertTrue($proration->isUpgrade);
        // About half of the 1000 they already paid is unused and comes off.
        $this->assertEqualsWithDelta(0.5, $proration->unusedFraction, 0.03);
        $this->assertEqualsWithDelta(500.0, $proration->unusedCredit, 30);
        $this->assertEqualsWithDelta(2500.0, $proration->amountDue, 30);

        // The new plan applies immediately — they asked for more.
        $this->assertSame($premium->getKey(), $subscription->fresh()->plan_id);
        // And they get the part of the new allowance covering the time left,
        // on top of the 500 the period already granted.
        $this->assertEqualsWithDelta(1500.0, (float) $this->credits->balance($this->user)->confirmed_balance, 60);
    }

    public function test_a_downgrade_waits_for_the_period_the_customer_paid_for(): void
    {
        $premium = $this->plan('Premium', 3000, 2000);
        $pro = $this->plan('Pro', 1000, 500);

        $subscription = $this->subscriptions->start($this->user, $premium);
        $this->travelTo($subscription->current_period_start->copy()->addDays(10));

        $proration = $this->subscriptions->changePlan($subscription->fresh(), $pro, 'INR');

        $this->assertFalse($proration->isUpgrade);
        // Nothing charged and nothing refunded: they keep what they bought.
        $this->assertEqualsWithDelta(0.0, $proration->amountDue, 0.000001);

        $fresh = $subscription->fresh();
        $this->assertSame($premium->getKey(), $fresh->plan_id);
        $this->assertSame($pro->getKey(), $fresh->pending_plan_id);
        $this->assertTrue($fresh->pending_plan_starts_at->equalTo($fresh->current_period_end));
    }

    public function test_a_scheduled_downgrade_applies_when_the_period_ends(): void
    {
        $premium = $this->plan('Premium', 3000, 2000);
        $pro = $this->plan('Pro', 1000, 500);

        $subscription = $this->subscriptions->start($this->user, $premium);
        $this->subscriptions->changePlan($subscription, $pro, 'INR');

        $this->travelTo($subscription->fresh()->current_period_end->copy()->addMinute());

        $this->assertSame(1, $this->subscriptions->applyScheduledChanges());
        $this->assertSame($pro->getKey(), $subscription->fresh()->plan_id);
        $this->assertNull($subscription->fresh()->pending_plan_id);
    }

    // -- cancelling -----------------------------------------------------------

    public function test_cancelling_keeps_the_period_the_customer_already_paid_for(): void
    {
        $pro = $this->plan('Pro', 1000, 500);
        $subscription = $this->subscriptions->start($this->user, $pro);

        $this->subscriptions->cancel($subscription);

        // Cancelling is not a refund. Cutting them off now would be keeping
        // money for a service withdrawn.
        $this->assertSame(Subscription::STATUS_CANCELLED, $subscription->fresh()->status);
        $this->assertTrue($subscription->fresh()->isLive());
    }

    public function test_a_cancelled_subscription_stops_when_its_period_runs_out(): void
    {
        $pro = $this->plan('Pro', 1000, 500);
        $subscription = $this->subscriptions->start($this->user, $pro);
        $this->subscriptions->cancel($subscription);

        $this->travelTo($subscription->current_period_end->copy()->addDay());

        $this->assertFalse($subscription->fresh()->isLive());
        $this->assertSame(1, $this->subscriptions->expireLapsed());
        $this->assertSame(Subscription::STATUS_EXPIRED, $subscription->fresh()->status);
    }

    public function test_an_immediate_cancellation_ends_it_at_once(): void
    {
        $pro = $this->plan('Pro', 1000, 500);
        $subscription = $this->subscriptions->start($this->user, $pro);

        $this->subscriptions->cancel($subscription, immediately: true);

        $this->assertSame(Subscription::STATUS_EXPIRED, $subscription->fresh()->status);
        $this->assertFalse($subscription->fresh()->isLive());
    }
}
