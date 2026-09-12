<?php

namespace Tests\Feature\Billing;

use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Models\PlanPrice;
use App\Domains\Billing\Services\InvoiceService;
use App\Domains\Credits\Services\CreditService;
use App\Domains\Security\Services\PermissionRegistry;
use App\Domains\Tax\Models\CustomerTaxProfile;
use App\Models\User;
use Database\Seeders\BillingSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** What a customer sees of their own billing. */
class CustomerBillingPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BillingSeeder::class);

        $this->user = User::factory()->create();
        $this->user->assignRole(PermissionRegistry::CUSTOMER);
        $this->user = $this->user->fresh();
    }

    private function publish(string $slug, float $price = 0, float $credits = 0, bool $default = false): Plan
    {
        $plan = Plan::where('slug', $slug)->firstOrFail();

        $plan->forceFill([
            'status' => Plan::STATUS_ACTIVE,
            'is_public' => true,
            'is_default' => $default,
            'credits_per_period' => $credits,
        ])->save();

        if ($price > 0) {
            PlanPrice::updateOrCreate(
                ['plan_id' => $plan->getKey(), 'currency' => 'INR'],
                ['amount' => $price, 'is_active' => true],
            );
        }

        return $plan->fresh('prices');
    }

    // -- pricing --------------------------------------------------------------

    public function test_the_pricing_page_is_public(): void
    {
        $this->publish('pro', 999);

        $this->get('/pricing')->assertOk()->assertSee('Pro');
    }

    public function test_a_draft_plan_is_never_on_sale(): void
    {
        $this->get('/pricing')->assertOk()->assertDontSee('Premium');
    }

    public function test_a_plan_with_no_price_in_this_currency_is_hidden_rather_than_converted(): void
    {
        // A converted price moves daily and ends in odd decimals — Addendum F
        // is explicit that per-market pricing is deliberate.
        $plan = $this->publish('pro');
        $plan->prices()->delete();

        $this->get('/pricing')->assertOk()->assertDontSee('Pro');
    }

    public function test_a_free_plan_needs_no_price_to_be_listed(): void
    {
        $this->publish('free');

        $this->get('/pricing')->assertOk()->assertSee('Free');
    }

    // -- the billing page -----------------------------------------------------

    public function test_billing_requires_signing_in(): void
    {
        $this->get('/billing')->assertRedirect();
    }

    public function test_a_customer_lands_on_the_default_plan_when_they_open_billing(): void
    {
        $this->publish('free', credits: 50, default: true);

        $this->actingAs($this->user)->get('/billing')
            ->assertOk()
            ->assertSee('Free')
            ->assertSee('50.00');
    }

    public function test_a_customer_sees_their_own_invoices_only(): void
    {
        $other = User::factory()->create();

        $mine = app(InvoiceService::class)->issue(
            app(InvoiceService::class)->draft($this->user, 'INR', [
                ['description' => 'My plan', 'unit_amount' => 100],
            ]),
        );

        $theirs = app(InvoiceService::class)->issue(
            app(InvoiceService::class)->draft($other, 'INR', [
                ['description' => 'Their plan', 'unit_amount' => 200],
            ]),
        );

        $this->actingAs($this->user)->get('/billing')
            ->assertOk()
            ->assertSee($mine->number)
            ->assertDontSee($theirs->number);

        // And the direct URL is not a way around it.
        $this->actingAs($this->user)->get('/billing/invoices/'.$theirs->uuid)->assertForbidden();
    }

    public function test_saving_billing_details_never_changes_an_invoice_already_issued(): void
    {
        CustomerTaxProfile::create([
            'user_id' => $this->user->getKey(),
            'billing_name' => 'Original Name',
            'country' => 'IN',
        ]);

        $invoice = app(InvoiceService::class)->issue(
            app(InvoiceService::class)->draft($this->user, 'INR', [
                ['description' => 'Plan', 'unit_amount' => 100],
            ]),
        );

        $this->actingAs($this->user)->post('/billing/details', [
            'billing_name' => 'Corrected Name',
            'country' => 'IN',
        ])->assertRedirect();

        // The invoice keeps the name it was issued with. A customer correcting
        // a typo would otherwise silently rewrite documents they have already
        // filed.
        $this->assertSame('Original Name', $invoice->fresh()->customer_name);
        $this->assertSame('Corrected Name', CustomerTaxProfile::where('user_id', $this->user->getKey())->value('billing_name'));
    }

    public function test_credits_are_only_shown_once_the_owner_publishes_a_plan(): void
    {
        app(CreditService::class)->grant($this->user, 10, 'Test');

        // Nothing published: the customer is not shown a balance for a system
        // that is not metering anything.
        $this->actingAs($this->user)->get('/billing')->assertOk()->assertDontSee('Where your credits went');

        $this->publish('free', credits: 50, default: true);

        $this->actingAs($this->user)->get('/billing')->assertOk()->assertSee('Where your credits went');
    }
}
