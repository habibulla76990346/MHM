<?php

namespace Tests\Feature\Billing;

use App\Domains\Credits\Models\CreditHold;
use App\Domains\Credits\Models\CreditLedgerEntry;
use App\Domains\Credits\Services\CreditService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * The Phase 6 gate for credit: the ledger is append-only, the cached balance
 * always equals it, and a hold is settled to what was really used.
 *
 * These are the assertions that decide whether a balance can be trusted. Every
 * customer-facing number and every revenue figure is derived from them.
 */
class CreditLedgerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private CreditService $credits;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->credits = app(CreditService::class);
    }

    // -- the invariant --------------------------------------------------------

    public function test_the_ledger_always_sums_to_the_cached_balance(): void
    {
        $this->credits->grant($this->user, 100, 'Opening grant');
        $this->credits->grant($this->user, 25.5, 'Promotion');

        $hold = $this->credits->hold($this->user, 40);
        $this->credits->settle($hold, 12.25);

        $this->credits->adjust($this->user, -5, 'Support goodwill correction', $this->user->getKey());

        // 100 + 25.5 - 12.25 - 5
        $this->assertEqualsWithDelta(108.25, $this->credits->ledgerTotal($this->user), 0.000001);
        $this->assertEqualsWithDelta(108.25, (float) $this->credits->balance($this->user)->confirmed_balance, 0.000001);
        $this->assertTrue($this->credits->reconciles($this->user));
    }

    public function test_every_entry_records_the_balance_it_produced(): void
    {
        $this->credits->grant($this->user, 60, 'Grant');
        $hold = $this->credits->hold($this->user, 20);
        $this->credits->settle($hold, 20);

        $entries = CreditLedgerEntry::where('user_id', $this->user->getKey())->orderBy('id')->get();

        $this->assertEqualsWithDelta(60.0, (float) $entries[0]->balance_after, 0.000001);
        $this->assertEqualsWithDelta(40.0, (float) $entries[1]->balance_after, 0.000001);
    }

    // -- append-only ----------------------------------------------------------

    public function test_a_ledger_entry_can_never_be_edited(): void
    {
        $entry = $this->credits->grant($this->user, 10, 'Grant');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('append-only');

        $entry->forceFill(['amount' => 1000])->save();
    }

    public function test_a_ledger_entry_can_never_be_deleted(): void
    {
        $entry = $this->credits->grant($this->user, 10, 'Grant');

        $this->expectException(RuntimeException::class);

        $entry->delete();
    }

    public function test_a_manual_adjustment_without_a_reason_is_refused(): void
    {
        $this->credits->grant($this->user, 10, 'Grant');

        $this->expectException(\InvalidArgumentException::class);

        // §19 asks for adjustments WITH a reason. One nobody can explain later
        // is indistinguishable from a mistake.
        $this->credits->adjust($this->user, 5, '   ', $this->user->getKey());
    }

    // -- holds ----------------------------------------------------------------

    public function test_a_hold_reserves_credit_without_spending_it(): void
    {
        $this->credits->grant($this->user, 50, 'Grant');

        $hold = $this->credits->hold($this->user, 30);
        $balance = $this->credits->balance($this->user)->fresh();

        $this->assertNotNull($hold);
        // Nothing has been charged yet — but nothing else can promise it.
        $this->assertEqualsWithDelta(50.0, (float) $balance->confirmed_balance, 0.000001);
        $this->assertEqualsWithDelta(30.0, (float) $balance->held_balance, 0.000001);
        $this->assertEqualsWithDelta(20.0, $balance->spendable(), 0.000001);
    }

    public function test_a_hold_beyond_the_spendable_balance_is_refused(): void
    {
        $this->credits->grant($this->user, 50, 'Grant');
        $this->credits->hold($this->user, 40);

        // 10 spendable, 20 asked for.
        $this->assertNull($this->credits->hold($this->user, 20));
    }

    public function test_settling_charges_the_real_cost_and_frees_the_rest(): void
    {
        $this->credits->grant($this->user, 100, 'Grant');

        $hold = $this->credits->hold($this->user, 40);
        $this->credits->settle($hold, 6.5);

        $balance = $this->credits->balance($this->user)->fresh();

        $this->assertEqualsWithDelta(93.5, (float) $balance->confirmed_balance, 0.000001);
        $this->assertEqualsWithDelta(0.0, (float) $balance->held_balance, 0.000001);
        $this->assertSame(CreditHold::SETTLED, $hold->fresh()->status);
    }

    public function test_settling_charges_the_real_cost_even_when_it_exceeds_the_estimate(): void
    {
        $this->credits->grant($this->user, 100, 'Grant');
        $hold = $this->credits->hold($this->user, 10);

        // A hold is a pre-authorisation the system made, not a price quoted to
        // the customer. A reply that genuinely cost 25 is charged at 25 —
        // capping at the estimate would make the owner subsidise every reply
        // that ran longer than expected.
        $this->credits->settle($hold, 25);

        $this->assertEqualsWithDelta(75.0, (float) $this->credits->balance($this->user)->fresh()->confirmed_balance, 0.000001);
    }

    public function test_a_charge_can_never_take_a_balance_below_zero(): void
    {
        $this->credits->grant($this->user, 10, 'Grant');
        $hold = $this->credits->hold($this->user, 10);

        // What is left unrecovered is bounded by the model's own output limit
        // and falls to the owner, which is the right place for the cost of an
        // estimate to land.
        $this->credits->settle($hold, 999);

        $balance = $this->credits->balance($this->user)->fresh();

        $this->assertEqualsWithDelta(0.0, (float) $balance->confirmed_balance, 0.000001);
        $this->assertTrue($this->credits->reconciles($this->user));
    }

    public function test_a_released_hold_charges_nothing(): void
    {
        $this->credits->grant($this->user, 100, 'Grant');
        $hold = $this->credits->hold($this->user, 25);

        $this->credits->release($hold);

        $balance = $this->credits->balance($this->user)->fresh();

        $this->assertEqualsWithDelta(100.0, (float) $balance->confirmed_balance, 0.000001);
        $this->assertEqualsWithDelta(0.0, (float) $balance->held_balance, 0.000001);
        $this->assertSame(CreditHold::RELEASED, $hold->fresh()->status);
    }

    public function test_a_hold_cannot_be_settled_twice(): void
    {
        $this->credits->grant($this->user, 100, 'Grant');
        $hold = $this->credits->hold($this->user, 20);

        $this->credits->settle($hold, 15);
        // A retried job must not charge again.
        $this->assertNull($this->credits->settle($hold, 15));

        $this->assertEqualsWithDelta(85.0, (float) $this->credits->balance($this->user)->fresh()->confirmed_balance, 0.000001);
    }

    public function test_an_abandoned_hold_is_released_rather_than_stranding_the_balance(): void
    {
        $this->credits->grant($this->user, 100, 'Grant');
        $hold = $this->credits->hold($this->user, 30);

        // A worker that died mid-call would otherwise hold this for ever, and
        // the customer would see credit they cannot spend.
        $hold->forceFill(['expires_at' => now()->subHour()])->save();

        $this->assertSame(1, $this->credits->releaseExpiredHolds());
        $this->assertEqualsWithDelta(100.0, $this->credits->spendable($this->user), 0.000001);
    }

    // -- refunds and expiry ---------------------------------------------------

    public function test_a_refund_takes_back_what_is_unspent_and_never_more(): void
    {
        $this->credits->grant($this->user, 100, 'Purchased credits', 'payment', 'pay_1');

        $hold = $this->credits->hold($this->user, 80);
        $this->credits->settle($hold, 70);

        // 30 left of the 100 they bought. The 70 they used cost real provider
        // money and is not recoverable by arithmetic.
        $revoked = $this->credits->revoke($this->user, 100, 'Payment pay_1 refunded', 'payment', 'pay_1');

        $this->assertEqualsWithDelta(30.0, $revoked, 0.000001);
        $this->assertEqualsWithDelta(0.0, (float) $this->credits->balance($this->user)->fresh()->confirmed_balance, 0.000001);
        // Never negative: trapping an honest customer below zero is not a
        // remedy.
        $this->assertTrue($this->credits->reconciles($this->user));
    }

    public function test_promotional_credit_expires_but_never_below_zero(): void
    {
        $this->credits->grant($this->user, 40, 'Promotion', 'promo', 'p1', now()->subDay());

        $hold = $this->credits->hold($this->user, 40);
        $this->credits->settle($hold, 35);

        $this->assertSame(1, $this->credits->expireCredits());

        // Only the 5 they had left. What they spent before it lapsed is theirs.
        $this->assertEqualsWithDelta(0.0, (float) $this->credits->balance($this->user)->fresh()->confirmed_balance, 0.000001);
        $this->assertTrue($this->credits->reconciles($this->user));
    }

    public function test_expiring_the_same_grant_twice_takes_nothing_extra(): void
    {
        $this->credits->grant($this->user, 20, 'Promotion', 'promo', 'p1', now()->subDay());
        $this->credits->grant($this->user, 50, 'Purchased', 'payment', 'pay_9');

        $this->credits->expireCredits();
        $this->credits->expireCredits();

        // The promotional 20 went; the 50 they paid for did not.
        $this->assertEqualsWithDelta(50.0, (float) $this->credits->balance($this->user)->fresh()->confirmed_balance, 0.000001);
    }

    public function test_an_adjustment_cannot_take_a_balance_below_zero(): void
    {
        $this->credits->grant($this->user, 10, 'Grant');

        $this->expectException(RuntimeException::class);

        $this->credits->adjust($this->user, -50, 'Typo in a support ticket', $this->user->getKey());
    }
}
