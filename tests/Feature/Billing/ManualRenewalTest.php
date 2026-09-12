<?php

namespace Tests\Feature\Billing;

use App\Domains\Billing\Models\Invoice;
use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Models\PlanPrice;
use App\Domains\Billing\Models\Subscription;
use App\Domains\Billing\Models\SubscriptionPeriod;
use App\Domains\Billing\Services\RenewalService;
use App\Domains\Billing\Services\SubscriptionService;
use App\Domains\Credits\Services\CreditService;
use App\Domains\Notifications\Mail\TemplatedMail;
use App\Domains\Notifications\Models\NotificationDelivery;
use App\Domains\Notifications\Support\NotificationEvent;
use App\Domains\Payments\Adapters\RazorpayAdapter;
use App\Domains\Payments\Models\Payment;
use App\Domains\Payments\Models\PaymentGatewayCredential;
use App\Domains\Payments\Models\PaymentGatewayRecord;
use App\Domains\Payments\Services\CheckoutService;
use App\Domains\Security\Services\PermissionRegistry;
use App\Models\User;
use Database\Seeders\BillingSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Manual renewal (Owner Addendum D §3): "the customer receives an invoice and
 * a payment link each period".
 *
 * WHAT THIS SUITE IS REALLY FOR. Before it, a subscription that could not be
 * auto-renewed simply ran out — no bill, no link, no warning, and the
 * customer's first news of it was losing access. So the assertions are about
 * the promises: an invoice arrives before the period ends, exactly one of it
 * however many times anything runs, a link that cannot be forged or reused,
 * payment settling through the ONE handler that already exists, and no
 * subscription ever ending without a way to renew having been offered first.
 */
class ManualRenewalTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Plan $plan;

    private Subscription $subscription;

    private string $gatewayStatus = 'captured';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BillingSeeder::class);

        $this->user = User::factory()->create(['email' => 'renewer@example.test', 'name' => 'Ravi']);
        $this->user->assignRole(PermissionRegistry::CUSTOMER);
        $this->user = $this->user->fresh();

        $this->plan = Plan::where('slug', 'pro')->firstOrFail();
        $this->plan->forceFill([
            'status' => Plan::STATUS_ACTIVE,
            'is_public' => true,
            'billing_cycle' => 'monthly',
            'credits_per_period' => 500,
        ])->save();

        PlanPrice::create(['plan_id' => $this->plan->getKey(), 'currency' => 'INR', 'amount' => 999]);

        $gateway = PaymentGatewayRecord::where('key', RazorpayAdapter::KEY)->firstOrFail();
        $gateway->forceFill([
            'status' => PaymentGatewayRecord::STATUS_ACTIVE,
            'mode' => PaymentGatewayRecord::MODE_SANDBOX,
            'is_default' => true,
        ])->save();

        PaymentGatewayCredential::create([
            'gateway_id' => $gateway->getKey(),
            'mode' => PaymentGatewayRecord::MODE_SANDBOX,
            'label' => 'Test',
            'credentials' => ['key_id' => 'rzp_test_ABC123', 'key_secret' => 'secret-abcdef123456'],
            'webhook_secret' => 'whsec-test-0123456789',
            'publishable_key' => 'rzp_test_ABC123',
            'status' => 'active',
        ]);

        $this->subscription = $this->subscribe();

        $this->installGatewayStub();
    }

    private function subscribe(?Plan $plan = null): Subscription
    {
        return Subscription::create([
            'user_id' => $this->user->getKey(),
            'plan_id' => ($plan ?: $this->plan)->getKey(),
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_start' => now()->subMonth(),
            'current_period_end' => now()->addDays(3),
            'currency' => 'INR',
            'amount' => 999,
            'renewal_mechanism' => 'manual',
        ]);
    }

    /** ONE stub reading mutable state — a second fake for the same pattern never replaces the first. */
    private function installGatewayStub(): void
    {
        Http::fake(['*' => function ($request) {
            $url = $request->url();

            if (str_contains($url, '/orders')) {
                return Http::response(['id' => 'order_REN1', 'amount' => 99900, 'currency' => 'INR', 'status' => 'created']);
            }

            if (str_contains($url, '/payments/pay_')) {
                return Http::response([
                    'id' => 'pay_REN1',
                    'amount' => 99900,
                    'currency' => 'INR',
                    'status' => $this->gatewayStatus,
                    'method' => 'upi',
                ]);
            }

            return Http::response(['items' => [], 'count' => 0]);
        }]);
    }

    private function renewals(): RenewalService
    {
        return app(RenewalService::class);
    }

    // -- the invoice and the link --------------------------------------------

    public function test_the_invoice_and_the_link_arrive_before_the_period_ends(): void
    {
        $this->assertSame(1, $this->renewals()->issueUpcoming());

        $invoice = Invoice::where('subscription_id', $this->subscription->getKey())->firstOrFail();

        // A real document: numbered, issued, dated to the period it covers.
        $this->assertNotNull($invoice->number);
        $this->assertTrue($invoice->isOutstanding());
        $this->assertSame(
            $this->subscription->current_period_end->toDateTimeString(),
            $invoice->renewal_period_start->toDateTimeString(),
        );

        // And the customer was told, with a link they can use.
        Mail::assertSent(TemplatedMail::class, function ($mail) use ($invoice) {
            return $mail->hasTo('renewer@example.test')
                && str_contains($mail->bodyText, (string) $invoice->number)
                && str_contains($mail->bodyText, '/renew/');
        });

        $this->assertDatabaseHas('notification_deliveries', [
            'event_key' => NotificationEvent::RENEWAL_DUE,
            'status' => NotificationDelivery::STATUS_SENT,
        ]);
    }

    public function test_running_it_repeatedly_raises_one_invoice_and_one_payment(): void
    {
        $this->renewals()->issueUpcoming();

        // The scheduler again, an administrator pressing "send it now", the
        // customer opening their billing page — all reach the same period.
        $this->renewals()->issueUpcoming();
        $this->renewals()->prepare($this->subscription->fresh());
        $this->renewals()->prepare($this->subscription->fresh());

        $this->assertSame(1, Invoice::where('subscription_id', $this->subscription->getKey())->count());
        $this->assertSame(1, Payment::where('purpose', Payment::PURPOSE_RENEWAL)->count());

        // And only the first one wrote to anybody.
        $this->assertSame(1, NotificationDelivery::where('event_key', NotificationEvent::RENEWAL_DUE)
            ->where('channel', NotificationEvent::CHANNEL_MAIL)->count());
    }

    public function test_a_second_period_gets_its_own_invoice(): void
    {
        $this->renewals()->issueUpcoming();

        // Renewed, so the subscription moves on. The NEXT period is a
        // different period and needs its own bill — the guard must be per
        // period, not per subscription.
        app(SubscriptionService::class)
            ->renew($this->subscription, $this->subscription->current_period_end);

        $this->travelTo(now()->addMonth());

        $this->renewals()->issueUpcoming();

        $this->assertSame(2, Invoice::where('subscription_id', $this->subscription->getKey())->count());
    }

    // -- the link is the authorisation ---------------------------------------

    public function test_the_link_is_signed_and_shows_only_what_the_email_already_said(): void
    {
        $notice = $this->renewals()->prepare($this->subscription);

        // Not signed in at all: the whole point of the link.
        $response = $this->get($notice->payUrl);

        $response->assertOk();
        $response->assertSee($notice->invoice->number);
        $response->assertSee('999.00');

        // Nothing that belongs to an account. A forwarded email must not
        // become a window into somebody's details.
        $response->assertDontSee('renewer@example.test');
        $response->assertDontSee('Ravi');
    }

    public function test_an_unsigned_or_tampered_link_is_refused(): void
    {
        $notice = $this->renewals()->prepare($this->subscription);

        // No signature at all.
        $this->get(route('renewal.show', $notice->payment))->assertForbidden();

        // Edited to point somewhere else, keeping the signature.
        $other = Payment::create([
            'user_id' => $this->user->getKey(),
            'gateway_id' => $notice->payment->gateway_id,
            'purpose' => Payment::PURPOSE_RENEWAL,
            'presentment_amount' => 1,
            'presentment_currency' => 'INR',
            'status' => Payment::STATUS_CREATED,
            'idempotency_key' => 'other-key',
        ]);

        $tampered = str_replace($notice->payment->uuid, $other->uuid, $notice->payUrl);

        $this->get($tampered)->assertForbidden();
    }

    public function test_an_expired_link_stops_working(): void
    {
        settings()->set('billing.renewal_link_days', 3);

        $notice = $this->renewals()->prepare($this->subscription);

        $this->get($notice->payUrl)->assertOk();

        $this->travelTo(now()->addDays(4));

        // An old email in a forwarded mailbox is not a way in for ever.
        $this->get($notice->payUrl)->assertForbidden();
    }

    public function test_a_signed_link_cannot_be_pointed_at_an_ordinary_checkout_payment(): void
    {
        ['payment' => $checkoutPayment] = app(CheckoutService::class)->start($this->user, $this->plan, 'INR');

        $this->assertSame(Payment::PURPOSE_SUBSCRIPTION, $checkoutPayment->purpose);

        // Signed correctly, but these public pages are for renewals. An
        // ordinary checkout's result belongs behind a login.
        $url = URL::temporarySignedRoute('renewal.show', now()->addDay(), ['payment' => $checkoutPayment->uuid]);

        $this->get($url)->assertNotFound();
    }

    // -- paying it -----------------------------------------------------------

    public function test_paying_the_link_renews_through_the_one_handler(): void
    {
        $notice = $this->renewals()->prepare($this->subscription);
        $endBefore = $this->subscription->current_period_end->copy();

        // Open the gateway, then come back the way the bank sends them.
        $this->post($notice->payUrl)->assertOk();

        $payment = $notice->payment->fresh();
        $payment->forceFill(['gateway_payment_id' => 'pay_REN1'])->save();

        $this->get(URL::signedRoute('renewal.return', ['payment' => $payment->uuid]))->assertOk();

        $settled = $payment->fresh();
        $subscription = $this->subscription->fresh();

        $this->assertTrue($settled->isPaid());
        $this->assertTrue($notice->invoice->fresh()->isPaid());
        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);
        $this->assertTrue($subscription->current_period_end->greaterThan($endBefore));

        // The period's credits arrived, once.
        $this->assertSame(1, SubscriptionPeriod::where('subscription_id', $subscription->getKey())
            ->where('period_start', $endBefore)->count());
        $this->assertEqualsWithDelta(500.0, (float) app(CreditService::class)->balance($this->user)->confirmed_balance, 0.000001);

        // NO SECOND INVOICE. The renewal arrived with its own, issued days
        // earlier and already mailed; paying settles that document.
        $this->assertSame(1, Invoice::where('subscription_id', $subscription->getKey())->count());
    }

    public function test_settling_the_same_renewal_again_changes_nothing(): void
    {
        $notice = $this->renewals()->prepare($this->subscription);
        $this->post($notice->payUrl);

        $payment = $notice->payment->fresh();
        $payment->forceFill(['gateway_payment_id' => 'pay_REN1'])->save();

        $checkout = app(CheckoutService::class);

        // A webhook, the browser returning, and the sweep — all three arrive.
        $checkout->settle($payment->fresh());
        $checkout->settle($payment->fresh());
        $checkout->settle($payment->fresh());

        $subscription = $this->subscription->fresh();

        $this->assertSame(1, SubscriptionPeriod::where('subscription_id', $subscription->getKey())->count());
        $this->assertEqualsWithDelta(500.0, (float) app(CreditService::class)->balance($this->user)->confirmed_balance, 0.000001);
        $this->assertSame(1, Invoice::where('subscription_id', $subscription->getKey())->count());

        // And the customer was thanked once, not three times.
        $this->assertSame(1, NotificationDelivery::where('event_key', NotificationEvent::PAYMENT_RECEIVED)
            ->where('channel', NotificationEvent::CHANNEL_MAIL)->count());
    }

    public function test_a_paid_link_stops_offering_to_take_money(): void
    {
        $notice = $this->renewals()->prepare($this->subscription);
        $this->post($notice->payUrl);

        $payment = $notice->payment->fresh();
        $payment->forceFill(['gateway_payment_id' => 'pay_REN1'])->save();
        app(CheckoutService::class)->settle($payment->fresh());

        $response = $this->get($notice->payUrl);

        $response->assertOk();
        $response->assertSee('paid');
        $response->assertDontSee('Pay now');
    }

    // -- nothing ends without a way to renew ---------------------------------

    public function test_an_unpaid_subscription_goes_past_due_and_keeps_working(): void
    {
        $this->renewals()->issueUpcoming();

        $this->travelTo($this->subscription->current_period_end->copy()->addDay());

        $this->assertSame(1, $this->renewals()->markOverdue());

        $subscription = $this->subscription->fresh();

        $this->assertSame(Subscription::STATUS_PAST_DUE, $subscription->status);
        // Still live. A customer who is a day late keeps a working account and
        // gets a link, rather than meeting a locked door.
        $this->assertTrue($subscription->isLive());

        $this->assertDatabaseHas('notification_deliveries', [
            'event_key' => NotificationEvent::RENEWAL_OVERDUE,
        ]);
    }

    public function test_it_only_ends_after_the_grace_period_and_the_customer_is_told(): void
    {
        settings()->set('billing.renewal_grace_days', 5);

        $this->renewals()->issueUpcoming();

        $this->travelTo($this->subscription->current_period_end->copy()->addDay());
        $this->renewals()->markOverdue();

        // Inside the grace period: nothing ends.
        $this->travelTo($this->subscription->current_period_end->copy()->addDays(4));
        $this->assertSame(0, $this->renewals()->endAfterGrace());
        $this->assertTrue($this->subscription->fresh()->isLive());

        $this->travelTo($this->subscription->current_period_end->copy()->addDays(6));
        $this->assertSame(1, $this->renewals()->endAfterGrace());

        $this->assertSame(Subscription::STATUS_EXPIRED, $this->subscription->fresh()->status);
        $this->assertFalse($this->subscription->fresh()->isLive());

        $this->assertDatabaseHas('notification_deliveries', [
            'event_key' => NotificationEvent::SUBSCRIPTION_EXPIRED,
        ]);
    }

    public function test_a_subscription_is_never_ended_without_having_been_invoiced_first(): void
    {
        // The whole point, stated as one assertion: run the entire lifecycle
        // and check that at no moment is a subscription expired while nobody
        // ever sent it a bill.
        settings()->set('billing.renewal_grace_days', 2);

        for ($day = 0; $day <= 10; $day++) {
            $this->renewals()->run();

            if ($this->subscription->fresh()->status === Subscription::STATUS_EXPIRED) {
                break;
            }

            $this->travelTo(now()->addDay());
        }

        $this->assertSame(Subscription::STATUS_EXPIRED, $this->subscription->fresh()->status);

        // It was billed, chased, and only then ended — in that order.
        $this->assertSame(1, Invoice::where('subscription_id', $this->subscription->getKey())->count());

        foreach ([NotificationEvent::RENEWAL_DUE, NotificationEvent::RENEWAL_OVERDUE, NotificationEvent::SUBSCRIPTION_EXPIRED] as $event) {
            $this->assertDatabaseHas('notification_deliveries', ['event_key' => $event]);
        }
    }

    public function test_a_cancelled_subscription_is_not_billed_again(): void
    {
        app(SubscriptionService::class)->cancel($this->subscription);

        $this->assertSame(0, $this->renewals()->issueUpcoming());
        $this->assertSame(0, Invoice::where('subscription_id', $this->subscription->getKey())->count());

        Mail::assertNothingSent();
    }

    public function test_a_free_plan_renews_itself_with_no_invoice_and_no_email(): void
    {
        $free = Plan::create([
            'name' => 'Free', 'slug' => 'free-tier', 'status' => Plan::STATUS_ACTIVE,
            'billing_cycle' => 'monthly', 'credits_per_period' => 50,
        ]);
        PlanPrice::create(['plan_id' => $free->getKey(), 'currency' => 'INR', 'amount' => 0]);

        $this->subscription->forceFill([
            'plan_id' => $free->getKey(),
            'amount' => 0,
            'current_period_end' => now()->subDay(),
        ])->save();

        $this->assertSame(1, $this->renewals()->renewFreePeriods());

        // A plan that costs nothing has nothing to invoice — but it still has
        // periods, which is how its allowance refreshes.
        $this->assertSame(0, Invoice::where('subscription_id', $this->subscription->getKey())->count());
        Mail::assertNothingSent();

        $this->assertTrue($this->subscription->fresh()->current_period_end->isFuture());
        $this->assertEqualsWithDelta(50.0, (float) app(CreditService::class)->balance($this->user)->confirmed_balance, 0.000001);
    }

    // -- the customer's own way in -------------------------------------------

    public function test_the_billing_page_offers_the_same_renewal_the_email_did(): void
    {
        $notice = $this->renewals()->prepare($this->subscription);

        $response = $this->actingAs($this->user)->get(route('billing'));

        $response->assertOk();
        $response->assertSee($notice->invoice->number);
        $response->assertSee('/renew/'.$notice->payment->uuid);

        // Opening the page did not raise a second bill.
        $this->assertSame(1, Invoice::where('subscription_id', $this->subscription->getKey())->count());
    }
}
