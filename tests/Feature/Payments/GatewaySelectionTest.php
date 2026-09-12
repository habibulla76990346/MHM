<?php

namespace Tests\Feature\Payments;

use App\Domains\Payments\Adapters\RazorpayAdapter;
use App\Domains\Payments\Contracts\SupportsOneTimePayment;
use App\Domains\Payments\Contracts\SupportsSubscriptions;
use App\Domains\Payments\Models\PaymentGatewayCredential;
use App\Domains\Payments\Models\PaymentGatewayRecord;
use App\Domains\Payments\Models\PaymentGatewayRule;
use App\Domains\Payments\Services\GatewaySelector;
use App\Domains\Payments\Services\PaymentGatewayRegistry;
use App\Domains\Payments\Support\Capability;
use Database\Seeders\BillingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The guarantee Addendum D §3 exists for: **a subscription is never routed to
 * a gateway that cannot renew it.**
 *
 * Getting this wrong does not produce an error. It produces a one-off payment
 * that never charges again, and nobody notices until the month the money does
 * not arrive.
 */
class GatewaySelectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(BillingSeeder::class);

        // Two more adapters, so the selector has a genuine choice to get
        // wrong: one that only takes single payments, one that can renew.
        app(PaymentGatewayRegistry::class)->register('one-shot', OneShotTestAdapter::class);
        app(PaymentGatewayRegistry::class)->register('recurring', RecurringTestAdapter::class);
    }

    private function gateway(string $key, string $adapter, int $priority = 100, array $currencies = [], array $countries = []): PaymentGatewayRecord
    {
        $gateway = PaymentGatewayRecord::create([
            'key' => $key,
            'name' => ucfirst($key),
            'adapter_class' => $adapter,
            'status' => PaymentGatewayRecord::STATUS_ACTIVE,
            'mode' => PaymentGatewayRecord::MODE_SANDBOX,
            'priority' => $priority,
            'supported_currencies' => $currencies,
            'supported_countries' => $countries,
            'api_base_url' => 'https://example.test',
        ]);

        PaymentGatewayCredential::create([
            'gateway_id' => $gateway->getKey(),
            'mode' => PaymentGatewayRecord::MODE_SANDBOX,
            'credentials' => ['key_id' => 'k', 'key_secret' => 's'],
            'webhook_secret' => 'w',
            'status' => 'active',
        ]);

        return $gateway->fresh('credentials');
    }

    private function selector(): GatewaySelector
    {
        return app(GatewaySelector::class);
    }

    // -- the capability guard -------------------------------------------------

    public function test_a_subscription_needing_renewal_never_goes_to_a_one_time_gateway(): void
    {
        // The one-shot gateway is FIRST by priority and would win on
        // preference alone.
        $this->gateway('one-shot', OneShotTestAdapter::class, priority: 1);
        $recurring = $this->gateway('recurring', RecurringTestAdapter::class, priority: 90);

        $decision = $this->selector()->select('subscription', 'INR', 'IN', null, 'gateway_subscription');

        $this->assertSame($recurring->getKey(), $decision->gateway?->getKey());
    }

    public function test_with_no_capable_gateway_it_fails_loudly_rather_than_downgrading(): void
    {
        $this->gateway('one-shot', OneShotTestAdapter::class);

        $decision = $this->selector()->select('subscription', 'INR', 'IN', null, 'gateway_subscription');

        $this->assertFalse($decision->chosen());
        // And says why, so an owner knows this is a configuration problem
        // rather than an outage.
        $this->assertStringContainsString('renew', strtolower($decision->explainFailure()));
    }

    public function test_a_routing_rule_cannot_widen_what_capability_allows(): void
    {
        $oneShot = $this->gateway('one-shot', OneShotTestAdapter::class, priority: 50);
        $this->gateway('recurring', RecurringTestAdapter::class, priority: 50);

        // An owner explicitly points subscriptions at the gateway that cannot
        // renew them. Preference orders; it never overrides.
        PaymentGatewayRule::create([
            'gateway_id' => $oneShot->getKey(),
            'payment_type' => 'subscription',
            'priority' => 1,
            'is_active' => true,
        ]);

        $decision = $this->selector()->select('subscription', 'INR', 'IN', null, 'gateway_subscription');

        $this->assertNotSame($oneShot->getKey(), $decision->gateway?->getKey());
    }

    public function test_invoice_and_pay_renewal_may_use_a_one_time_gateway(): void
    {
        $oneShot = $this->gateway('one-shot', OneShotTestAdapter::class);

        // A deliberate admin choice with different customer messaging, not an
        // accident: manual renewal only ever needs one payment at a time.
        $decision = $this->selector()->select('subscription', 'INR', 'IN', null, 'manual');

        $this->assertSame($oneShot->getKey(), $decision->gateway?->getKey());
    }

    // -- the other hard filters ----------------------------------------------

    public function test_a_gateway_without_credentials_for_its_mode_is_not_used(): void
    {
        $gateway = $this->gateway('one-shot', OneShotTestAdapter::class);
        $gateway->credentials()->delete();

        $decision = $this->selector()->select('one_time', 'INR', 'IN');

        $this->assertFalse($decision->chosen());
        $this->assertStringContainsString('credentials', strtolower($decision->explainFailure()));
    }

    public function test_a_gateway_in_maintenance_is_skipped(): void
    {
        $out = $this->gateway('one-shot', OneShotTestAdapter::class, priority: 1);
        $out->forceFill(['maintenance_mode' => true])->save();

        $other = $this->gateway('recurring', RecurringTestAdapter::class, priority: 5);

        $decision = $this->selector()->select('one_time', 'INR', 'IN');

        $this->assertSame($other->getKey(), $decision->gateway?->getKey());
    }

    public function test_a_currency_the_gateway_is_not_set_up_for_is_refused(): void
    {
        $this->gateway('one-shot', OneShotTestAdapter::class, currencies: ['INR']);

        $decision = $this->selector()->select('one_time', 'USD', 'US');

        $this->assertFalse($decision->chosen());
        $this->assertStringContainsString('USD', $decision->explainFailure());
    }

    public function test_an_empty_currency_list_means_whatever_the_account_allows(): void
    {
        // Which currencies a merchant account can accept is a fact about the
        // owner's agreement, not something software can determine.
        $gateway = $this->gateway('one-shot', OneShotTestAdapter::class, currencies: []);

        $this->assertTrue($this->selector()->select('one_time', 'USD', 'US')->chosen());
    }

    public function test_a_gateway_with_no_adapter_installed_is_skipped(): void
    {
        $orphan = $this->gateway('gone', 'App\\Nonexistent\\Adapter');
        $working = $this->gateway('one-shot', OneShotTestAdapter::class, priority: 200);

        $decision = $this->selector()->select('one_time', 'INR', 'IN');

        $this->assertSame($working->getKey(), $decision->gateway?->getKey());
    }

    // -- the owner's own preferences -----------------------------------------

    public function test_a_more_specific_rule_wins(): void
    {
        $general = $this->gateway('one-shot', OneShotTestAdapter::class, priority: 1);
        $specific = $this->gateway('recurring', RecurringTestAdapter::class, priority: 99);

        PaymentGatewayRule::create([
            'gateway_id' => $general->getKey(), 'payment_type' => 'one_time',
            'priority' => 1, 'is_active' => true,
        ]);

        PaymentGatewayRule::create([
            'gateway_id' => $specific->getKey(), 'payment_type' => 'one_time',
            'currency' => 'USD', 'country' => 'US', 'priority' => 50, 'is_active' => true,
        ]);

        $this->assertSame(
            $specific->getKey(),
            $this->selector()->select('one_time', 'USD', 'US')->gateway?->getKey(),
        );

        // And the general rule still governs everything else.
        $this->assertSame(
            $general->getKey(),
            $this->selector()->select('one_time', 'INR', 'IN')->gateway?->getKey(),
        );
    }

    public function test_the_default_gateway_breaks_a_tie(): void
    {
        $this->gateway('one-shot', OneShotTestAdapter::class, priority: 10);
        $preferred = $this->gateway('recurring', RecurringTestAdapter::class, priority: 10);
        $preferred->forceFill(['is_default' => true])->save();

        $this->assertSame(
            $preferred->getKey(),
            $this->selector()->select('one_time', 'INR', 'IN')->gateway?->getKey(),
        );
    }

    public function test_the_shipped_adapter_declares_only_what_it_implements(): void
    {
        $adapter = app(RazorpayAdapter::class);

        // Declaring a capability that is not implemented is how a subscription
        // ends up somewhere that cannot renew it. This adapter claims one-time
        // payments and refunds, and does not claim recurring.
        $this->assertTrue($adapter->supports(Capability::ONE_TIME));
        $this->assertTrue($adapter->supports(Capability::REFUNDS));
        $this->assertFalse($adapter->supports(Capability::RECURRING));
        $this->assertNotInstanceOf(SupportsSubscriptions::class, $adapter);
        $this->assertInstanceOf(SupportsOneTimePayment::class, $adapter);
    }
}
