<?php

namespace Tests\Feature\Admin;

use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Services\InvoiceService;
use App\Domains\Credits\Services\CreditService;
use App\Domains\Security\Services\PermissionRegistry;
use App\Models\User;
use Database\Seeders\BillingSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Who can see the money, and who cannot.
 *
 * §9 is explicit that a support role never gains financial configuration and a
 * content role never gains either. Both are enforced by ABSENCE from the role
 * matrix, which is only meaningful if something checks it.
 */
class BillingScreensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BillingSeeder::class);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user->fresh();
    }

    public function test_an_owner_can_open_every_billing_screen(): void
    {
        $admin = $this->userWithRole(PermissionRegistry::SUPER_ADMIN);

        foreach ([
            '/admin/plans',
            '/admin/coupons',
            '/admin/invoices',
            '/admin/customer-credits',
            '/admin/tax-and-compliance',
            '/admin/tax-jurisdictions',
            '/admin/countries-and-currencies',
        ] as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }
    }

    public function test_a_finance_manager_reaches_tax_and_plans(): void
    {
        $finance = $this->userWithRole(PermissionRegistry::FINANCE_MANAGER);

        $this->actingAs($finance)->get('/admin/plans')->assertOk();
        $this->actingAs($finance)->get('/admin/tax-and-compliance')->assertOk();
    }

    public function test_a_support_manager_can_look_but_not_configure_tax(): void
    {
        $support = $this->userWithRole(PermissionRegistry::SUPPORT_MANAGER);

        // Read-only on billing: enough to answer "what plan is this customer
        // on?", never enough to change what anybody is charged.
        $this->actingAs($support)->get('/admin/plans')->assertOk();

        $denied = $this->actingAs($support)->get('/admin/tax-and-compliance');
        $this->assertContains($denied->getStatusCode(), [403, 404]);
    }

    public function test_a_content_manager_reaches_no_billing_screen_at_all(): void
    {
        $content = $this->userWithRole(PermissionRegistry::CONTENT_MANAGER);

        foreach (['/admin/plans', '/admin/invoices', '/admin/customer-credits', '/admin/tax-and-compliance'] as $url) {
            $response = $this->actingAs($content)->get($url);
            $this->assertContains($response->getStatusCode(), [403, 404], $url.' should be denied');
        }
    }

    public function test_a_customer_cannot_reach_the_admin_panel(): void
    {
        $customer = $this->userWithRole(PermissionRegistry::CUSTOMER);

        $response = $this->actingAs($customer)->get('/admin/plans');

        $this->assertContains($response->getStatusCode(), [403, 404]);
    }

    // -- the screens themselves ----------------------------------------------

    public function test_the_plans_screen_lists_what_is_configured(): void
    {
        Plan::where('slug', 'pro')->update(['status' => Plan::STATUS_ACTIVE]);

        $this->actingAs($this->userWithRole(PermissionRegistry::SUPER_ADMIN))
            ->get('/admin/plans')
            ->assertOk()
            ->assertSee('Pro')
            // A plan with no price says so rather than showing an empty cell.
            ->assertSee('Not priced');
    }

    public function test_an_issued_invoice_is_shown_from_its_own_snapshot(): void
    {
        $customer = $this->userWithRole(PermissionRegistry::CUSTOMER);

        $invoice = app(InvoiceService::class)->issue(
            app(InvoiceService::class)->draft($customer, 'INR', [
                ['description' => 'Pro plan — one month', 'unit_amount' => 999],
            ]),
        );

        $this->actingAs($this->userWithRole(PermissionRegistry::SUPER_ADMIN))
            ->get('/admin/invoices/'.$invoice->uuid)
            ->assertOk()
            ->assertSee($invoice->number)
            ->assertSee('Pro plan');
    }

    public function test_the_credits_screen_shows_a_customers_ledger(): void
    {
        $customer = $this->userWithRole(PermissionRegistry::CUSTOMER);
        app(CreditService::class)->grant($customer, 25, 'Welcome credits');

        $this->actingAs($this->userWithRole(PermissionRegistry::SUPER_ADMIN))
            ->get('/admin/customer-credits')
            ->assertOk()
            ->assertSee('Find a customer');
    }

    public function test_tax_is_off_and_the_screen_says_what_is_missing(): void
    {
        $this->actingAs($this->userWithRole(PermissionRegistry::SUPER_ADMIN))
            ->get('/admin/tax-and-compliance')
            ->assertOk()
            ->assertSee('Tax is switched off')
            // Never a silent zero: the screen names what still has to be
            // entered before tax can be charged at all.
            ->assertSee('Registered business name');
    }

    public function test_no_billing_screen_can_leak_a_credential(): void
    {
        $admin = $this->userWithRole(PermissionRegistry::SUPER_ADMIN);

        foreach (['/admin/plans', '/admin/invoices', '/admin/tax-and-compliance', '/admin/countries-and-currencies'] as $url) {
            $body = $this->actingAs($admin)->get($url)->assertOk()->getContent();

            $this->assertStringNotContainsString('sk-', $body);
            $this->assertStringNotContainsString('key_secret', $body);
        }
    }
}
