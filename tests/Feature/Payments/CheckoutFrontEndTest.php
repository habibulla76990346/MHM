<?php

namespace Tests\Feature\Payments;

use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Models\PlanPrice;
use App\Domains\Payments\Adapters\FixtureGatewayAdapter;
use App\Domains\Payments\Adapters\RazorpayAdapter;
use App\Domains\Payments\Models\PaymentGatewayCredential;
use App\Domains\Payments\Models\PaymentGatewayRecord;
use App\Domains\Payments\Services\PaymentGatewayRegistry;
use App\Domains\Security\Services\PermissionRegistry;
use App\Models\User;
use Database\Seeders\BillingSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * BLK-1: the checkout page must be able to take money.
 *
 * The behaviour gate (`npm run test:checkout`) presses the button in a real
 * browser. This covers what a browser cannot: that the SERVER hands the page
 * everything a driver needs, for every gateway, and that no gateway is named
 * anywhere it should not be.
 *
 * WHY BOTH. For a whole phase the markup carried an order id and a Pay button
 * and nothing read either — the server side was complete and correct, and the
 * page was inert. PHPUnit could not see it because it never rendered the page;
 * the responsive gate could not see it because it measures pixels. The gap was
 * exactly here.
 */
class CheckoutFrontEndTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BillingSeeder::class);

        $this->user = User::factory()->create();
        $this->user->assignRole(PermissionRegistry::CUSTOMER);
        $this->user = $this->user->fresh();

        $this->plan = Plan::where('slug', 'pro')->firstOrFail();
        $this->plan->forceFill(['status' => Plan::STATUS_ACTIVE, 'is_public' => true, 'billing_cycle' => 'monthly'])->save();
        PlanPrice::create(['plan_id' => $this->plan->getKey(), 'currency' => 'INR', 'amount' => 999]);

        Http::fake(['*' => Http::response(['id' => 'order_TEST123', 'amount' => 99900, 'currency' => 'INR'])]);
    }

    private function useGateway(string $key, string $class): PaymentGatewayRecord
    {
        PaymentGatewayRecord::query()->update(['is_default' => false, 'status' => PaymentGatewayRecord::STATUS_DISABLED]);

        $gateway = PaymentGatewayRecord::firstOrCreate(
            ['key' => $key],
            [
                'name' => ucfirst($key),
                'adapter_class' => $class,
                'mode' => PaymentGatewayRecord::MODE_SANDBOX,
                'capabilities' => app($class)->capabilities(),
                'checkout_mode' => app($class)->checkoutMode(),
                'supported_currencies' => [],
                'supported_countries' => [],
            ],
        );

        $gateway->forceFill([
            'status' => PaymentGatewayRecord::STATUS_ACTIVE,
            'is_default' => true,
            'mode' => PaymentGatewayRecord::MODE_SANDBOX,
            'adapter_class' => $class,
        ])->save();

        PaymentGatewayCredential::updateOrCreate(
            ['gateway_id' => $gateway->getKey(), 'mode' => PaymentGatewayRecord::MODE_SANDBOX],
            [
                'label' => 'Test',
                'credentials' => ['key_id' => 'rzp_test_ABC123', 'key_secret' => 'secret-abcdef123456'],
                'webhook_secret' => 'whsec-test-0123456789',
                'publishable_key' => 'pk_test_ABC123',
                'status' => 'active',
            ],
        );

        return $gateway->fresh('credentials');
    }

    // -- the page carries what a driver needs --------------------------------

    public function test_the_checkout_page_names_a_driver_and_binds_the_button(): void
    {
        $this->useGateway(FixtureGatewayAdapter::KEY, FixtureGatewayAdapter::class);

        $response = $this->actingAs($this->user)->get(route('checkout.start', $this->plan));

        $response->assertOk();

        // Everything the browser needs to open a payment. Missing any one of
        // them is the inert page this test exists to prevent.
        $response->assertSee('id="checkout"', false);
        $response->assertSee('data-driver="fixture"', false);
        $response->assertSee('id="pay-now"', false);
        $response->assertSee('data-return=', false);
        $response->assertSee('data-checkout-status', false);
    }

    public function test_every_shipped_gateway_declares_a_driver(): void
    {
        // A gateway with no driver is a Pay button that cannot work. The
        // contract requires one, so this is a promise the type system makes
        // and this test makes visible.
        foreach (array_keys(app(PaymentGatewayRegistry::class)->all()) as $key) {
            $adapter = app(PaymentGatewayRegistry::class)->byKey($key);

            $this->assertNotNull($adapter, $key.' is registered but cannot be built.');
            $this->assertNotSame('', $adapter->checkoutDriver(), $key.' declares no checkout driver.');
        }
    }

    public function test_a_gateway_that_needs_a_script_declares_where_it_is(): void
    {
        $gateway = $this->useGateway(RazorpayAdapter::KEY, RazorpayAdapter::class);
        $adapter = app(PaymentGatewayRegistry::class)->for($gateway);

        $sdk = $adapter->checkoutSdkUrl();

        $this->assertNotNull($sdk);
        // Over TLS, and pinned to a version rather than to "latest" — a
        // checkout script that can change under a live payment page is a
        // change nobody tested.
        $this->assertStringStartsWith('https://', $sdk);
        $this->assertMatchesRegularExpression('#/v\d+/#', $sdk);
    }

    public function test_the_development_gateway_needs_no_script_at_all(): void
    {
        // It takes no money and reaches no network, which is what makes the
        // behaviour gate runnable offline and in CI.
        $this->assertNull(app(FixtureGatewayAdapter::class)->checkoutSdkUrl());
    }

    public function test_the_page_carries_the_publishable_key_and_never_the_secret(): void
    {
        $this->useGateway(RazorpayAdapter::KEY, RazorpayAdapter::class);

        $response = $this->actingAs($this->user)->get(route('checkout.start', $this->plan));

        $response->assertOk();
        $response->assertSee('pk_test_ABC123', false);
        // The one that would cost real money if it reached a page.
        $response->assertDontSee('secret-abcdef123456', false);
        $response->assertDontSee('key_secret', false);
    }

    public function test_the_renewal_page_gets_the_same_driver_wiring(): void
    {
        // The renewal path renders the same view. Wiring the checkout page and
        // forgetting this one would leave every emailed payment link inert.
        $view = file_get_contents(resource_path('views/billing/checkout.blade.php'));

        $this->assertStringContainsString('$checkoutDriver', $view);

        $renewal = file_get_contents(app_path('Http/Controllers/Billing/RenewalController.php'));

        $this->assertStringContainsString("'checkoutDriver'", $renewal);
        $this->assertStringContainsString("'checkoutSdk'", $renewal);
    }

    // -- the front end obeys the same rule as the back end --------------------

    public function test_no_gateway_name_appears_in_front_end_checkout_code(): void
    {
        // The same rule as `NoHardCodedTaxOrGatewayTest`, carried across into
        // JavaScript: a gateway may be named in its own driver — the browser's
        // mirror of `app/Domains/Payments/Adapters/` — and nowhere else.
        $offences = [];
        $tokens = ['razorpay', 'phonepe', 'payu', 'cashfree', 'ccavenue', 'stripe', 'paddle', 'paypal'];

        foreach ($this->frontEndCheckoutFiles() as $path => $code) {
            if (str_contains($path, 'checkout/drivers/')) {
                continue;
            }

            foreach ($tokens as $token) {
                // The registry maps a driver NAME to its module, so the import
                // line is the one legitimate mention outside a driver.
                $withoutImports = preg_replace('/^\s*import .*$/m', '', $code) ?? $code;
                $withoutRegistry = preg_replace('/const DRIVERS = \{[^}]*\};/s', '', $withoutImports) ?? $withoutImports;

                if (str_contains(strtolower($withoutRegistry), $token)) {
                    $offences[] = $path.' — '.$token;
                }
            }
        }

        $this->assertSame([], $offences, implode("\n", array_merge(
            ['A gateway is named outside its own driver. Checkout code must speak in outcomes:'],
            $offences,
        )));
    }

    public function test_the_checkout_page_itself_names_no_gateway(): void
    {
        $view = strtolower((string) file_get_contents(resource_path('views/billing/checkout.blade.php')));

        foreach (['razorpay', 'stripe', 'payu', 'cashfree', 'paypal'] as $token) {
            $this->assertStringNotContainsString($token, $view,
                'The checkout page names a gateway. It must render whatever the adapter declared.');
        }
    }

    /** @return array<string, string> */
    private function frontEndCheckoutFiles(): array
    {
        $files = [];
        $root = base_path('resources/js');

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'js') {
                $files[str_replace(base_path().'/', '', $file->getPathname())] = (string) file_get_contents($file->getPathname());
            }
        }

        return $files;
    }
}
