<?php

namespace Tests\Feature\Billing;

use App\Domains\Billing\Models\Invoice;
use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Models\PlanPrice;
use App\Domains\Billing\Models\Subscription;
use App\Domains\Billing\Services\InvoiceNumberAllocator;
use App\Domains\Billing\Services\InvoiceService;
use App\Domains\Billing\Services\RenewalService;
use App\Domains\Credits\Models\CreditHold;
use App\Domains\Credits\Services\CreditService;
use App\Domains\Payments\Adapters\FixtureGatewayAdapter;
use App\Domains\Payments\Models\Payment;
use App\Domains\Payments\Models\PaymentGatewayCredential;
use App\Domains\Payments\Models\PaymentGatewayRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The Phase 6 gate item that cannot be proved sequentially: **parallel
 * requests cannot drive a balance negative**.
 *
 * WHY THIS TEST FORKS. The bug it guards against only exists between two
 * processes: both read a balance of 100, both decide they can afford 40, both
 * commit, and the owner has sold 80 credits' worth of AI for 100. A sequential
 * test passes whether or not the lock exists, so it proves nothing about the
 * thing that actually goes wrong in production.
 *
 * WHY NOT RefreshDatabase. It wraps each test in a transaction that is rolled
 * back, so a forked child would see none of the parent's data. Truncation
 * commits, which is what makes the children able to contend at all — and
 * contention is the whole point.
 */
class CreditConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is needed to run genuinely parallel requests.');
        }
    }

    /**
     * Leave the database as this test found it.
     *
     * Truncation COMMITS, unlike the transaction every other test runs inside,
     * so rows written here survive into whatever runs next — and a suite where
     * one test's leftovers change another's result is worse than no suite. The
     * trait clears tables before its own tests; this clears them after, for
     * everyone else's.
     */
    protected function tearDown(): void
    {
        $this->truncateDatabaseTables();

        parent::tearDown();
    }

    public function test_parallel_holds_cannot_oversell_a_balance(): void
    {
        $user = User::factory()->create();
        app(CreditService::class)->grant($user, 100, 'Opening grant');

        // Ten simultaneous requests for 30 credits against a balance of 100.
        // Exactly three can be afforded. A system without a row lock lets far
        // more through, and the owner pays for the difference.
        $granted = $this->inParallel(10, function () use ($user) {
            return app(CreditService::class)->hold($user, 30) !== null;
        });

        $balance = app(CreditService::class)->balance($user)->fresh();

        $this->assertSame(3, $granted, 'Exactly three holds of 30 fit inside a balance of 100.');
        $this->assertSame(3, CreditHold::where('user_id', $user->getKey())->where('status', CreditHold::HELD)->count());
        $this->assertEqualsWithDelta(90.0, (float) $balance->held_balance, 0.000001);
        // The one figure that must never be untrue.
        $this->assertGreaterThanOrEqual(0.0, $balance->spendable());
    }

    public function test_parallel_settlements_of_one_hold_charge_once(): void
    {
        $user = User::factory()->create();
        $credits = app(CreditService::class);
        $credits->grant($user, 100, 'Opening grant');
        $hold = $credits->hold($user, 50);

        // Five workers all told to settle the same hold — a webhook retried, a
        // queue that delivered twice, a customer who refreshed.
        $this->inParallel(5, function () use ($hold) {
            return app(CreditService::class)->settle($hold, 20) !== null;
        });

        $balance = $credits->balance($user)->fresh();

        $this->assertEqualsWithDelta(80.0, (float) $balance->confirmed_balance, 0.000001);
        $this->assertEqualsWithDelta(0.0, (float) $balance->held_balance, 0.000001);
        $this->assertTrue($credits->reconciles($user));
    }

    public function test_invoice_numbers_are_gap_free_under_concurrent_checkout(): void
    {
        $user = User::factory()->create();
        app(InvoiceNumberAllocator::class)->sequence();

        // Eight checkouts completing at the same instant. Without the row lock
        // several read the same current value and are issued the SAME number —
        // duplicate invoice numbers are a compliance problem in most
        // jurisdictions and cannot be corrected after the fact.
        $issued = $this->inParallel(8, function () use ($user) {
            $invoices = app(InvoiceService::class);

            $draft = $invoices->draft($user, 'INR', [
                ['description' => 'Plan — one month', 'unit_amount' => 100],
            ]);

            return $invoices->issue($draft)->number !== null;
        });

        $numbers = Invoice::whereNotNull('number')->pluck('number')->all();

        $this->assertSame(8, $issued);
        $this->assertCount(8, $numbers, 'Every checkout produced an invoice.');
        $this->assertCount(8, array_unique($numbers), 'No two invoices may carry the same number.');

        $values = array_map(fn ($n) => (int) preg_replace('/\D/', '', substr($n, -6)), $numbers);
        sort($values);

        // Sequential with no gaps: 1..8, never 1,1,2,5.
        $this->assertSame(range(1, 8), $values);
    }

    /**
     * The renewal guarantee that cannot be proved sequentially: **a period is
     * invoiced once**, however many things reach it at the same instant.
     *
     * Three of them genuinely can: the nightly scheduler, an administrator
     * pressing "send the renewal now", and the customer opening their billing
     * page. Sequentially they all find the invoice the first one made. In
     * parallel they can all find NO invoice and all raise one — and a customer
     * who receives three bills for one month, each with its own number, is a
     * problem that cannot be corrected by deleting two of them, because a
     * number that has been issued cannot be un-issued.
     */
    public function test_parallel_renewal_preparation_raises_one_invoice(): void
    {
        $user = User::factory()->create();

        $plan = Plan::create([
            'name' => 'Pro', 'slug' => 'pro-concurrency', 'status' => Plan::STATUS_ACTIVE,
            'billing_cycle' => 'monthly', 'credits_per_period' => 100,
        ]);
        PlanPrice::create(['plan_id' => $plan->getKey(), 'currency' => 'INR', 'amount' => 999]);

        $gateway = PaymentGatewayRecord::create([
            'key' => FixtureGatewayAdapter::KEY, 'name' => 'Fixture',
            'adapter_class' => FixtureGatewayAdapter::class,
            'status' => PaymentGatewayRecord::STATUS_ACTIVE,
            'mode' => PaymentGatewayRecord::MODE_SANDBOX,
            'is_default' => true, 'priority' => 1,
        ]);
        PaymentGatewayCredential::create([
            'gateway_id' => $gateway->getKey(),
            'mode' => PaymentGatewayRecord::MODE_SANDBOX,
            'label' => 'Test',
            'credentials' => ['key_id' => 'fixture'],
            'status' => 'active',
        ]);

        $subscription = Subscription::create([
            'user_id' => $user->getKey(), 'plan_id' => $plan->getKey(),
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_start' => now()->subMonth(),
            'current_period_end' => now()->addDay(),
            'currency' => 'INR', 'amount' => 999, 'renewal_mechanism' => 'manual',
        ]);

        $this->inParallel(6, function () use ($subscription) {
            return app(RenewalService::class)->prepare($subscription->fresh()) !== null;
        });

        $invoices = Invoice::where('subscription_id', $subscription->getKey())->get();

        $this->assertCount(1, $invoices, 'Six simultaneous renewals must produce one invoice.');
        $this->assertNotNull($invoices->first()->number);

        // And one payment: six payment rows would mean a customer could be
        // charged more than once for the same month.
        $this->assertSame(1, Payment::where('subscription_id', $subscription->getKey())->count());
    }

    /**
     * Run a closure in N forked children and count how many returned true.
     *
     * Each child reconnects first: a forked process inherits the parent's
     * database socket, and two processes reading one socket corrupt each
     * other's results in ways that look like random test failures.
     */
    private function inParallel(int $children, callable $work): int
    {
        $pids = [];

        for ($i = 0; $i < $children; $i++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->fail('Could not fork a child process.');
            }

            if ($pid === 0) {
                $code = 1;

                try {
                    DB::purge();
                    DB::reconnect();

                    $code = $work() ? 0 : 1;
                } catch (\Throwable) {
                    $code = 2;
                }

                // Hard exit: a child must not run PHPUnit's shutdown handlers,
                // or the suite reports N copies of the same result.
                exit($code);
            }

            $pids[] = $pid;
        }

        $succeeded = 0;

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);

            if (pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0) {
                $succeeded++;
            }
        }

        // The parent's own connection has been read by children since it last
        // spoke; start fresh before asserting.
        DB::purge();
        DB::reconnect();

        return $succeeded;
    }
}
