<?php

namespace Tests\Feature\Payments;

use App\Domains\Billing\Models\Invoice;
use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Models\PlanPrice;
use App\Domains\Billing\Models\Subscription;
use App\Domains\Billing\Models\SubscriptionPeriod;
use App\Domains\Credits\Services\CreditService;
use App\Domains\Payments\Adapters\RazorpayAdapter;
use App\Domains\Payments\Models\Payment;
use App\Domains\Payments\Models\PaymentGatewayCredential;
use App\Domains\Payments\Models\PaymentGatewayRecord;
use App\Domains\Payments\Models\PaymentTransaction;
use App\Domains\Payments\Models\PaymentWebhookEvent;
use App\Domains\Payments\Services\CheckoutService;
use App\Domains\Payments\Services\ReconciliationService;
use App\Domains\Security\Services\PermissionRegistry;
use App\Models\User;
use Database\Seeders\BillingSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Phase 6 payment gate.
 *
 * Every assertion here is about money moving exactly once. The failures they
 * guard against are the expensive ones: a replayed webhook granting a second
 * month of credits, a lost webhook leaving a paying customer with nothing, and
 * an unsigned request being believed.
 */
class PaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'whsec-test-0123456789';

    private User $user;

    private Plan $plan;

    private PaymentGatewayRecord $gateway;

    /** What the fake gateway currently says about the payment. */
    private string $gatewayStatus = 'captured';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BillingSeeder::class);

        $this->user = User::factory()->create();
        $this->user->assignRole(PermissionRegistry::CUSTOMER);
        $this->user = $this->user->fresh();

        $this->plan = Plan::where('slug', 'pro')->firstOrFail();
        $this->plan->forceFill([
            'status' => Plan::STATUS_ACTIVE,
            'is_public' => true,
            'credits_per_period' => 500,
            'billing_cycle' => 'monthly',
        ])->save();

        PlanPrice::create([
            'plan_id' => $this->plan->getKey(),
            'currency' => 'INR',
            'amount' => 999,
        ]);

        $this->gateway = PaymentGatewayRecord::where('key', RazorpayAdapter::KEY)->firstOrFail();
        $this->gateway->forceFill([
            'status' => PaymentGatewayRecord::STATUS_ACTIVE,
            'mode' => PaymentGatewayRecord::MODE_SANDBOX,
            'is_default' => true,
        ])->save();

        PaymentGatewayCredential::create([
            'gateway_id' => $this->gateway->getKey(),
            'mode' => PaymentGatewayRecord::MODE_SANDBOX,
            'label' => 'Test',
            'credentials' => ['key_id' => 'rzp_test_ABC123', 'key_secret' => 'secret-abcdef123456'],
            'webhook_secret' => self::WEBHOOK_SECRET,
            'publishable_key' => 'rzp_test_ABC123',
            'status' => 'active',
        ]);

        $this->gateway = $this->gateway->fresh('credentials');

        $this->installGatewayStub();
    }

    /**
     * ONE stub, reading mutable state.
     *
     * A second Http::fake() for the same pattern does not replace the first,
     * so re-faking to simulate a change silently keeps the original response —
     * a trap that has bitten this project four times now.
     */
    private function installGatewayStub(): void
    {
        Http::fake(['*' => function ($request) {
            $url = $request->url();

            if (str_contains($url, '/orders')) {
                return Http::response([
                    'id' => 'order_TEST123',
                    'amount' => 99900,
                    'currency' => 'INR',
                    'status' => 'created',
                ]);
            }

            if (str_contains($url, '/refund')) {
                return Http::response(['id' => 'rfnd_TEST1', 'amount' => 99900, 'status' => 'processed']);
            }

            if (str_contains($url, '/payments/pay_')) {
                return Http::response([
                    'id' => 'pay_TEST999',
                    'amount' => 99900,
                    'currency' => 'INR',
                    'status' => $this->gatewayStatus,
                    'method' => 'upi',
                    // What the account is actually credited.
                    'base_amount' => 99900,
                ]);
            }

            return Http::response(['items' => [], 'count' => 0]);
        }]);
    }

    private function checkout(): array
    {
        return app(CheckoutService::class)->start($this->user, $this->plan, 'INR');
    }

    /** A signed webhook body, exactly as the gateway would send it. */
    private function webhook(string $paymentId = 'pay_TEST999', string $event = 'payment.captured'): array
    {
        $body = json_encode([
            'event' => $event,
            'payload' => ['payment' => ['entity' => [
                'id' => $paymentId,
                'order_id' => 'order_TEST123',
                'amount' => 99900,
                'currency' => 'INR',
                'status' => 'captured',
                'notes' => [],
            ]]],
        ]);

        return [$body, hash_hmac('sha256', $body, self::WEBHOOK_SECRET)];
    }

    private function credits(): CreditService
    {
        return app(CreditService::class);
    }

    // -- the gate items -------------------------------------------------------

    public function test_a_webhook_replayed_five_times_grants_credits_once(): void
    {
        ['payment' => $payment] = $this->checkout();
        [$body, $signature] = $this->webhook();

        for ($i = 0; $i < 5; $i++) {
            $response = $this->call(
                'POST',
                '/webhooks/payments/'.RazorpayAdapter::KEY,
                [],
                [],
                [],
                ['HTTP_X_RAZORPAY_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
                $body,
            );

            $response->assertOk();
        }

        // Once. Five deliveries of the same event, one month of credits.
        $this->assertEqualsWithDelta(500.0, (float) $this->credits()->balance($this->user)->confirmed_balance, 0.000001);
        $this->assertSame(1, SubscriptionPeriod::count());
        $this->assertSame(1, PaymentWebhookEvent::where('signature_valid', true)->count());
        $this->assertSame(5, (int) PaymentWebhookEvent::where('signature_valid', true)->value('attempts'));
        // The event row is what CLAIMS the work: a delivery that loses the
        // race does nothing at all, rather than doing it again and relying on
        // a later guard to make it harmless.
        $this->assertSame(
            1,
            PaymentTransaction::where('type', 'webhook')->count(),
            'Only the first delivery of an event may be acted on.',
        );
        $this->assertSame(Payment::STATUS_PAID, $payment->fresh()->status);
        // And exactly one invoice, with exactly one number.
        $this->assertSame(1, Invoice::whereNotNull('number')->count());
    }

    public function test_an_unsigned_webhook_is_rejected_and_recorded(): void
    {
        $this->checkout();
        [$body] = $this->webhook();

        $this->call('POST', '/webhooks/payments/'.RazorpayAdapter::KEY, [], [], [], ['CONTENT_TYPE' => 'application/json'], $body)
            ->assertStatus(401);

        $this->assertEqualsWithDelta(0.0, (float) $this->credits()->balance($this->user)->confirmed_balance, 0.000001);

        // Recorded, not silently dropped: a run of these is either a wrong
        // secret or somebody probing the endpoint.
        $rejected = PaymentWebhookEvent::where('signature_valid', false)->first();
        $this->assertNotNull($rejected);
        $this->assertDatabaseHas('activity_logs', ['action' => 'payments.webhook_rejected']);
    }

    public function test_a_wrongly_signed_webhook_is_rejected(): void
    {
        $this->checkout();
        [$body] = $this->webhook();

        $this->call(
            'POST',
            '/webhooks/payments/'.RazorpayAdapter::KEY,
            [], [], [],
            ['HTTP_X_RAZORPAY_SIGNATURE' => hash_hmac('sha256', $body, 'the-wrong-secret'), 'CONTENT_TYPE' => 'application/json'],
            $body,
        )->assertStatus(401);

        $this->assertSame(0, SubscriptionPeriod::count());
    }

    public function test_a_body_altered_after_signing_is_rejected(): void
    {
        $this->checkout();
        [$body, $signature] = $this->webhook();

        // The signature is over the RAW bytes, so changing one character
        // anywhere in the body invalidates it.
        $tampered = str_replace('99900', '1', $body);

        $this->call(
            'POST',
            '/webhooks/payments/'.RazorpayAdapter::KEY,
            [], [], [],
            ['HTTP_X_RAZORPAY_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
            $tampered,
        )->assertStatus(401);
    }

    public function test_a_payment_whose_webhook_never_arrives_is_settled_by_the_sweep(): void
    {
        ['payment' => $payment] = $this->checkout();

        // The customer paid and closed the tab. No return, no webhook.
        $payment->forceFill([
            'gateway_payment_id' => 'pay_TEST999',
            'created_at' => now()->subMinutes(20),
        ])->save();

        $result = app(ReconciliationService::class)->sweep(olderThanMinutes: 5);

        $this->assertSame(1, $result['settled']);
        $this->assertSame(Payment::STATUS_PAID, $payment->fresh()->status);
        // Without the sweep, money is taken and nothing is delivered.
        $this->assertEqualsWithDelta(500.0, (float) $this->credits()->balance($this->user)->confirmed_balance, 0.000001);
        $this->assertNotNull($payment->fresh()->invoice_id);
    }

    public function test_the_sweep_and_a_late_webhook_together_still_grant_once(): void
    {
        ['payment' => $payment] = $this->checkout();
        $payment->forceFill(['gateway_payment_id' => 'pay_TEST999', 'created_at' => now()->subMinutes(20)])->save();

        app(ReconciliationService::class)->sweep(olderThanMinutes: 5);

        [$body, $signature] = $this->webhook();
        $this->call('POST', '/webhooks/payments/'.RazorpayAdapter::KEY, [], [], [],
            ['HTTP_X_RAZORPAY_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'], $body)->assertOk();

        $this->assertEqualsWithDelta(500.0, (float) $this->credits()->balance($this->user)->confirmed_balance, 0.000001);
        $this->assertSame(1, SubscriptionPeriod::count());
    }

    public function test_a_pending_payment_is_not_credited(): void
    {
        $this->gatewayStatus = 'authorized';

        ['payment' => $payment] = $this->checkout();
        $payment->forceFill(['gateway_payment_id' => 'pay_TEST999', 'created_at' => now()->subMinutes(20)])->save();

        app(ReconciliationService::class)->sweep(olderThanMinutes: 5);

        // "Authorized" is a hold that can expire. Treating it as paid is how a
        // customer gets credits for money that is later released.
        $this->assertSame(Payment::STATUS_PENDING, $payment->fresh()->status);
        $this->assertEqualsWithDelta(0.0, (float) $this->credits()->balance($this->user)->confirmed_balance, 0.000001);
    }

    public function test_a_double_submitted_checkout_reuses_one_payment(): void
    {
        ['payment' => $first] = $this->checkout();
        ['payment' => $second] = $this->checkout();

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(1, Payment::count());
    }

    // -- what it does to the account -----------------------------------------

    public function test_a_paid_subscription_starts_the_plan_and_issues_an_invoice(): void
    {
        ['payment' => $payment] = $this->checkout();
        [$body, $signature] = $this->webhook();

        $this->call('POST', '/webhooks/payments/'.RazorpayAdapter::KEY, [], [], [],
            ['HTTP_X_RAZORPAY_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'], $body)->assertOk();

        $subscription = Subscription::where('user_id', $this->user->getKey())->first();

        $this->assertNotNull($subscription);
        $this->assertSame($this->plan->getKey(), $subscription->plan_id);
        $this->assertTrue($subscription->isLive());

        $invoice = Invoice::first();
        $this->assertNotNull($invoice->number);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        $this->assertEqualsWithDelta(999.0, (float) $invoice->total, 0.01);
    }

    public function test_no_card_data_is_ever_written_to_the_database(): void
    {
        ['payment' => $payment] = $this->checkout();

        // A gateway that echoes card details back — several do — must not
        // leave them in our tables.
        $body = json_encode([
            'event' => 'payment.captured',
            'payload' => ['payment' => ['entity' => [
                'id' => 'pay_TEST999',
                'order_id' => 'order_TEST123',
                'amount' => 99900,
                'currency' => 'INR',
                'status' => 'captured',
                'card' => ['number' => '4111111111111111', 'cvv' => '123', 'expiry_month' => '12'],
                'notes' => [],
            ]]],
        ]);

        $this->call('POST', '/webhooks/payments/'.RazorpayAdapter::KEY, [], [], [],
            ['HTTP_X_RAZORPAY_SIGNATURE' => hash_hmac('sha256', $body, self::WEBHOOK_SECRET), 'CONTENT_TYPE' => 'application/json'],
            $body)->assertOk();

        foreach (['payment_webhook_events', 'payment_transactions', 'payments'] as $table) {
            $dump = json_encode(DB::table($table)->get());

            $this->assertStringNotContainsString('4111111111111111', $dump, $table.' must never hold a card number');
            $this->assertStringNotContainsString('"cvv"', $dump, $table.' must never hold a CVV');
        }
    }

    public function test_a_credential_never_reaches_a_serialised_model(): void
    {
        $credential = $this->gateway->activeCredential();

        $json = json_encode($credential->toArray());

        // $hidden makes this structural rather than a habit: no API resource,
        // no Livewire snapshot and no log line can carry it.
        $this->assertStringNotContainsString('secret-abcdef123456', $json);
        $this->assertStringNotContainsString(self::WEBHOOK_SECRET, $json);
        // But the panel can still say WHICH key is in place.
        $this->assertSame('…3456', $credential->hint);
        // And the publishable identifier is deliberately visible.
        $this->assertStringContainsString('rzp_test_ABC123', $json);
    }

    public function test_the_checkout_page_renders_no_secret(): void
    {
        $body = $this->actingAs($this->user)
            ->get('/checkout/'.$this->plan->uuid)
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('secret-abcdef123456', $body);
        $this->assertStringNotContainsString(self::WEBHOOK_SECRET, $body);
        // The publishable key IS rendered — that is what it is for.
        $this->assertStringContainsString('rzp_test_ABC123', $body);
    }

    public function test_an_unknown_gateway_endpoint_is_a_404_not_an_error(): void
    {
        $this->call('POST', '/webhooks/payments/not-a-gateway', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')
            ->assertStatus(404);
    }
}
