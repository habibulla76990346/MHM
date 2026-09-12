<?php

namespace Tests\Feature\Payments;

use App\Domains\Billing\Models\Invoice;
use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Models\PlanPrice;
use App\Domains\Credits\Services\CreditService;
use App\Domains\Payments\Adapters\RazorpayAdapter;
use App\Domains\Payments\Models\Payment;
use App\Domains\Payments\Models\PaymentGatewayCredential;
use App\Domains\Payments\Models\PaymentGatewayRecord;
use App\Domains\Payments\Models\Refund;
use App\Domains\Payments\Services\CheckoutService;
use App\Domains\Payments\Services\PaymentGatewayRegistry;
use App\Domains\Payments\Services\RefundService;
use App\Domains\Security\Services\PermissionRegistry;
use App\Models\User;
use Database\Seeders\BillingSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Refunds, and the owner's decision about what happens to the credits.
 *
 * Take back what is UNSPENT, never below zero. What the customer already used
 * cost real provider money and cannot be recovered by arithmetic.
 */
class RefundTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'whsec-refund-test';

    private User $user;

    private Plan $plan;

    private PaymentGatewayRecord $gateway;

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
            'credits_per_period' => 1000,
        ])->save();

        PlanPrice::create(['plan_id' => $this->plan->getKey(), 'currency' => 'INR', 'amount' => 1000]);

        $this->gateway = PaymentGatewayRecord::where('key', RazorpayAdapter::KEY)->firstOrFail();
        $this->gateway->forceFill([
            'status' => PaymentGatewayRecord::STATUS_ACTIVE,
            'is_default' => true,
        ])->save();

        PaymentGatewayCredential::create([
            'gateway_id' => $this->gateway->getKey(),
            'mode' => PaymentGatewayRecord::MODE_SANDBOX,
            'credentials' => ['key_id' => 'rzp_test_R', 'key_secret' => 'refund-secret-9999'],
            'webhook_secret' => self::WEBHOOK_SECRET,
            'publishable_key' => 'rzp_test_R',
            'status' => 'active',
        ]);

        Http::fake(['*' => function ($request) {
            $url = $request->url();

            if (str_contains($url, '/orders')) {
                return Http::response(['id' => 'order_R1', 'amount' => 100000, 'currency' => 'INR', 'status' => 'created']);
            }

            if (str_contains($url, '/refund')) {
                return Http::response([
                    'id' => 'rfnd_R1',
                    'amount' => (int) ($request->data()['amount'] ?? 0),
                    'status' => 'processed',
                ]);
            }

            return Http::response([
                'id' => 'pay_R1',
                'amount' => 100000,
                'currency' => 'INR',
                'status' => 'captured',
                'base_amount' => 100000,
            ]);
        }]);
    }

    private function paidPayment(): Payment
    {
        ['payment' => $payment] = app(CheckoutService::class)->start($this->user, $this->plan, 'INR');

        $payment->forceFill(['gateway_payment_id' => 'pay_R1'])->save();

        return app(CheckoutService::class)->settle($payment);
    }

    private function credits(): CreditService
    {
        return app(CreditService::class);
    }

    public function test_a_full_refund_takes_back_only_the_unspent_credits(): void
    {
        $payment = $this->paidPayment();

        $this->assertEqualsWithDelta(1000.0, (float) $this->credits()->balance($this->user)->confirmed_balance, 0.000001);

        // They used 300 before asking for their money back.
        $hold = $this->credits()->hold($this->user, 300);
        $this->credits()->settle($hold, 300);

        $refund = app(RefundService::class)->refund($payment, 1000, 'Customer changed their mind');

        $this->assertSame(Refund::COMPLETED, $refund->status);
        $this->assertEqualsWithDelta(700.0, (float) $refund->credits_revoked, 0.000001);
        $this->assertEqualsWithDelta(0.0, (float) $this->credits()->balance($this->user)->fresh()->confirmed_balance, 0.000001);
        // Never negative. The 300 they spent is not recoverable by arithmetic.
        $this->assertTrue($this->credits()->reconciles($this->user));
        $this->assertSame(Payment::STATUS_REFUNDED, $payment->fresh()->status);
    }

    public function test_a_refund_produces_a_credit_note_rather_than_editing_the_invoice(): void
    {
        $payment = $this->paidPayment();
        $invoice = $payment->fresh()->invoice;

        app(RefundService::class)->refund($payment, 1000, 'Duplicate charge');

        $this->assertSame(1, $invoice->creditNotes()->count());
        // The invoice itself is untouched, and voided rather than deleted.
        $this->assertEqualsWithDelta(1000.0, (float) $invoice->fresh()->total, 0.01);
        $this->assertSame(Invoice::STATUS_VOID, $invoice->fresh()->status);
    }

    public function test_a_partial_refund_takes_back_a_proportional_share(): void
    {
        $payment = $this->paidPayment();

        app(RefundService::class)->refund($payment, 400, 'Partial goodwill');

        // 40% of the payment, so 40% of the credits it granted.
        $this->assertEqualsWithDelta(600.0, (float) $this->credits()->balance($this->user)->fresh()->confirmed_balance, 0.000001);
        $this->assertSame(Payment::STATUS_PARTIALLY_REFUNDED, $payment->fresh()->status);
    }

    public function test_a_refund_cannot_exceed_what_is_left(): void
    {
        $payment = $this->paidPayment();
        app(RefundService::class)->refund($payment, 600, 'First');

        $this->expectException(RuntimeException::class);

        app(RefundService::class)->refund($payment->fresh(), 600, 'And again');
    }

    public function test_a_refund_must_carry_a_reason(): void
    {
        $payment = $this->paidPayment();

        $this->expectException(RuntimeException::class);

        app(RefundService::class)->refund($payment, 100, '   ');
    }

    public function test_an_unpaid_payment_cannot_be_refunded(): void
    {
        ['payment' => $payment] = app(CheckoutService::class)->start($this->user, $this->plan, 'INR');

        $this->expectException(RuntimeException::class);

        app(RefundService::class)->refund($payment, 100, 'Nothing to refund');
    }

    public function test_a_gateway_that_cannot_refund_says_so_rather_than_pretending(): void
    {
        $payment = $this->paidPayment();

        // An owner who thinks a refund happened and discovers weeks later that
        // it did not has a far worse problem than one told plainly.
        app(PaymentGatewayRegistry::class)
            ->register('one-shot', OneShotTestAdapter::class);

        $payment->gateway->forceFill([
            'adapter_class' => OneShotTestAdapter::class,
        ])->save();

        app(PaymentGatewayRegistry::class)->flush();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot process refunds');

        app(RefundService::class)->refund($payment->fresh(), 100, 'Try it');
    }

    public function test_the_refund_is_audited(): void
    {
        $payment = $this->paidPayment();

        app(RefundService::class)->refund($payment, 1000, 'Recorded for the audit trail', $this->user->getKey());

        $this->assertDatabaseHas('activity_logs', ['action' => 'payments.refunded']);
    }
}
