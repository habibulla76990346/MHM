<?php

namespace App\Domains\Credits\Services;

use App\Domains\Credits\Models\CreditBalance;
use App\Domains\Credits\Models\CreditHold;
use App\Domains\Credits\Models\CreditLedgerEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The credit ledger (§19).
 *
 * EVERY WRITE HAPPENS UNDER A ROW LOCK on the balance, inside a transaction
 * that also writes the ledger row. Two things follow, and both are the point:
 *
 *   1. The cached balance can never disagree with the sum of the ledger,
 *      because neither is ever written without the other.
 *   2. Parallel requests cannot both pass an affordability check against the
 *      same balance. `SELECT … FOR UPDATE` makes the second one wait for the
 *      first to commit, so ten simultaneous calls against a balance that
 *      covers one result in one success and nine refusals — not ten successes
 *      and a negative balance the owner pays for.
 *
 * A CHECK IN PHP CANNOT DO THIS. `if ($balance >= $amount)` is true in both
 * processes at the same instant; only the database can serialise them.
 */
class CreditService
{
    /** How long a hold survives if nothing ever resolves it. */
    private const HOLD_MINUTES = 30;

    public function balance(User $user): CreditBalance
    {
        return CreditBalance::firstOrCreate(
            ['user_id' => $user->getKey()],
            ['confirmed_balance' => 0, 'held_balance' => 0],
        );
    }

    public function spendable(User $user): float
    {
        return $this->balance($user)->spendable();
    }

    // -- putting credit in ---------------------------------------------------

    public function grant(
        User $user,
        float $amount,
        string $reason,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?\DateTimeInterface $expiresAt = null,
        ?int $actorId = null,
        string $type = CreditLedgerEntry::GRANT,
    ): ?CreditLedgerEntry {
        if ($amount <= 0) {
            return null;
        }

        return $this->write($user, $amount, $type, $reason, $referenceType, $referenceId, $expiresAt, $actorId);
    }

    /**
     * A manual correction by an administrator.
     *
     * The reason is REQUIRED by the signature, not by a form: §19 asks for
     * adjustments with a reason, and an adjustment nobody can explain later is
     * indistinguishable from a mistake or a theft.
     */
    public function adjust(User $user, float $amount, string $reason, int $actorId): CreditLedgerEntry
    {
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('A manual credit adjustment must carry a reason.');
        }

        return $this->write(
            $user,
            $amount,
            CreditLedgerEntry::ADJUSTMENT,
            $reason,
            actorId: $actorId,
            allowNegativeBalance: false,
        );
    }

    /**
     * Take back credits a refunded payment granted.
     *
     * Only what is UNSPENT, and never below zero (the owner's decision). What
     * the customer already used cost real provider money and cannot be
     * recovered by arithmetic; trapping them at a negative balance would only
     * punish someone who might have refunded for a good reason.
     *
     * @return float how much was actually revoked
     */
    public function revoke(User $user, float $amount, string $reason, ?string $referenceType = null, ?string $referenceId = null): float
    {
        if ($amount <= 0) {
            return 0.0;
        }

        return (float) DB::transaction(function () use ($user, $amount, $reason, $referenceType, $referenceId) {
            $balance = $this->lock($user);
            $recoverable = min($amount, max(0.0, (float) $balance->confirmed_balance));

            if ($recoverable <= 0) {
                return 0.0;
            }

            $this->commit($balance, -$recoverable, CreditLedgerEntry::REVOCATION, $reason, $referenceType, $referenceId);

            return $recoverable;
        });
    }

    /**
     * Remove credit that has run out of time or allowance.
     *
     * Separate from `revoke()` because the reason matters in the ledger: an
     * expiry is the plan working as configured, a revocation follows a refund,
     * and an owner reading the history needs to tell them apart. Never takes
     * the balance below zero.
     *
     * @return float how much was actually removed
     */
    public function expire(User $user, float $amount, string $reason, ?string $referenceType = null, ?string $referenceId = null): float
    {
        if ($amount <= 0) {
            return 0.0;
        }

        return (float) DB::transaction(function () use ($user, $amount, $reason, $referenceType, $referenceId) {
            $balance = $this->lock($user);
            $removable = min($amount, max(0.0, (float) $balance->confirmed_balance));

            if ($removable <= 0) {
                return 0.0;
            }

            $this->commit($balance, -$removable, CreditLedgerEntry::EXPIRY, $reason, $referenceType, $referenceId);

            return $removable;
        });
    }

    // -- taking credit out ---------------------------------------------------

    /**
     * Pre-authorise an estimated cost.
     *
     * Returns null when the customer cannot afford it — a refusal, not an
     * exception, because "you are out of credit" is an ordinary answer the
     * caller has to give the customer either way.
     */
    public function hold(User $user, float $amount, ?string $referenceType = null, ?string $referenceId = null): ?CreditHold
    {
        if ($amount <= 0) {
            // A free model still gets a hold row, so settlement has something
            // to attach to and the audit trail has no gaps.
            $amount = 0.0;
        }

        return DB::transaction(function () use ($user, $amount, $referenceType, $referenceId) {
            $balance = $this->lock($user);

            if ($balance->spendable() < $amount) {
                return null;
            }

            $balance->forceFill([
                'held_balance' => (float) $balance->held_balance + $amount,
            ])->save();

            return CreditHold::create([
                'user_id' => $user->getKey(),
                'amount' => $amount,
                'status' => CreditHold::HELD,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'expires_at' => now()->addMinutes(self::HOLD_MINUTES),
            ]);
        });
    }

    /**
     * Charge what the call actually cost and let the rest go.
     *
     * THE REAL COST, NOT THE ESTIMATE. Settling to the estimate would
     * overcharge every short reply, and capping at the estimate would make the
     * owner subsidise every one that ran long — a hold is a pre-authorisation
     * the system made, not a price quoted to the customer.
     *
     * The one cap is the balance itself: a charge never takes an account below
     * zero. What that leaves unrecovered is bounded by the model's own output
     * limit and is the owner's cost of estimating, which is the right place
     * for it to fall.
     */
    public function settle(CreditHold $hold, float $actualAmount, string $reason = 'AI usage'): ?CreditLedgerEntry
    {
        return DB::transaction(function () use ($hold, $actualAmount, $reason) {
            $hold = CreditHold::whereKey($hold->getKey())->lockForUpdate()->first();

            if (! $hold || ! $hold->isOpen()) {
                // Already settled or released — a retried job must not charge
                // a second time.
                return null;
            }

            $user = $hold->user;
            $balance = $this->lock($user);
            $charge = max(0.0, min($actualAmount, (float) $balance->confirmed_balance));

            $balance->forceFill([
                'held_balance' => max(0.0, (float) $balance->held_balance - (float) $hold->amount),
            ])->save();

            $hold->forceFill([
                'status' => CreditHold::SETTLED,
                'settled_amount' => $charge,
                'resolved_at' => now(),
            ])->save();

            if ($charge <= 0) {
                return null;
            }

            return $this->commit(
                $balance->fresh(),
                -$charge,
                CreditLedgerEntry::DEDUCTION,
                $reason,
                $hold->reference_type,
                $hold->reference_id,
            );
        });
    }

    /**
     * Let a hold go without charging anything.
     *
     * The customer pays for answers, never for attempts: a provider failure,
     * a stop before any text arrived, or a crashed worker all end here.
     */
    public function release(CreditHold $hold): void
    {
        DB::transaction(function () use ($hold) {
            $hold = CreditHold::whereKey($hold->getKey())->lockForUpdate()->first();

            if (! $hold || ! $hold->isOpen()) {
                return;
            }

            $balance = $this->lock($hold->user);

            $balance->forceFill([
                'held_balance' => max(0.0, (float) $balance->held_balance - (float) $hold->amount),
            ])->save();

            $hold->forceFill(['status' => CreditHold::RELEASED, 'resolved_at' => now()])->save();
        });
    }

    /**
     * Release holds nothing ever resolved.
     *
     * A worker that died mid-call would otherwise hold a customer's balance
     * for ever, and the customer would see credit they cannot spend with no
     * way to find out why.
     */
    public function releaseExpiredHolds(): int
    {
        $released = 0;

        CreditHold::where('status', CreditHold::HELD)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->chunkById(100, function ($holds) use (&$released) {
                foreach ($holds as $hold) {
                    $this->release($hold);
                    $released++;
                }
            });

        return $released;
    }

    /**
     * Expire credits that have passed their date.
     *
     * Only unspent credit expires, and only down to zero: a customer who spent
     * a promotional grant before it lapsed keeps what they bought with it.
     */
    public function expireCredits(): int
    {
        $expired = 0;

        $grants = CreditLedgerEntry::whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->where('amount', '>', 0)
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('credit_ledger as e')
                    ->whereColumn('e.reference_id', 'credit_ledger.id')
                    ->where('e.reference_type', 'credit_expiry');
            })
            ->get();

        foreach ($grants as $grant) {
            DB::transaction(function () use ($grant, &$expired) {
                $balance = $this->lock($grant->user);
                $amount = min((float) $grant->amount, max(0.0, (float) $balance->confirmed_balance));

                if ($amount <= 0) {
                    return;
                }

                $this->commit(
                    $balance,
                    -$amount,
                    CreditLedgerEntry::EXPIRY,
                    'Promotional credit expired',
                    'credit_expiry',
                    (string) $grant->getKey(),
                );

                $expired++;
            });
        }

        return $expired;
    }

    // -- the invariant -------------------------------------------------------

    /**
     * Does the cached balance still equal the ledger?
     *
     * If this is ever false something has written a balance outside this
     * service, and every figure the customer sees is suspect. It is asserted
     * in the tests and surfaced in diagnostics rather than left to be noticed
     * by a customer.
     */
    public function reconciles(User $user): bool
    {
        $ledger = (float) CreditLedgerEntry::where('user_id', $user->getKey())->sum('amount');
        $cached = (float) $this->balance($user)->confirmed_balance;

        return abs($ledger - $cached) < 0.000001;
    }

    public function ledgerTotal(User $user): float
    {
        return (float) CreditLedgerEntry::where('user_id', $user->getKey())->sum('amount');
    }

    // -- internals -----------------------------------------------------------

    /** The balance row, locked for the rest of this transaction. */
    private function lock(User $user): CreditBalance
    {
        $this->balance($user);

        return CreditBalance::where('user_id', $user->getKey())->lockForUpdate()->first();
    }

    private function write(
        User $user,
        float $amount,
        string $type,
        string $reason,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?\DateTimeInterface $expiresAt = null,
        ?int $actorId = null,
        bool $allowNegativeBalance = false,
    ): CreditLedgerEntry {
        return DB::transaction(function () use ($user, $amount, $type, $reason, $referenceType, $referenceId, $expiresAt, $actorId, $allowNegativeBalance) {
            $balance = $this->lock($user);

            if (! $allowNegativeBalance && (float) $balance->confirmed_balance + $amount < 0) {
                throw new \RuntimeException('That adjustment would take the balance below zero.');
            }

            return $this->commit($balance, $amount, $type, $reason, $referenceType, $referenceId, $expiresAt, $actorId);
        });
    }

    /**
     * The one place a balance moves.
     *
     * The ledger row and the cached total are written together, inside the
     * caller's transaction and under its lock, so they cannot drift.
     */
    private function commit(
        CreditBalance $balance,
        float $amount,
        string $type,
        string $reason,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?\DateTimeInterface $expiresAt = null,
        ?int $actorId = null,
    ): CreditLedgerEntry {
        $after = round((float) $balance->confirmed_balance + $amount, 6);

        $balance->forceFill(['confirmed_balance' => $after])->save();

        return CreditLedgerEntry::create([
            'user_id' => $balance->user_id,
            'entry_type' => $type,
            'amount' => round($amount, 6),
            'balance_after' => $after,
            'reason' => $reason,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'expires_at' => $expiresAt,
            'actor_id' => $actorId,
        ]);
    }
}
