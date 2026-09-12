<?php

namespace App\Domains\Billing\Services;

use App\Domains\Billing\Models\InvoiceNumberSequence;
use Illuminate\Support\Facades\DB;

/**
 * Allocating the next invoice number (Addendum F §3).
 *
 * TWO THINGS MAKE THIS CORRECT, and both are easy to get wrong:
 *
 *  1. The sequence row is locked with `SELECT … FOR UPDATE` and incremented
 *     inside the caller's transaction. Two simultaneous checkouts therefore
 *     queue rather than both reading the same current value — which is how a
 *     system issues two invoices with the same number, a compliance problem in
 *     most jurisdictions.
 *
 *  2. It is called only when an invoice is ACTUALLY ISSUED. A number allocated
 *     on a payment attempt is burnt when the card is declined, and the gaps
 *     that leaves are exactly what an auditor asks about.
 */
class InvoiceNumberAllocator
{
    public const DEFAULT_KEY = 'invoice';

    /**
     * The next number, and the counter moved.
     *
     * MUST be called inside a transaction — the lock is only held until the
     * enclosing transaction commits, and without one the number is allocated
     * and released immediately, which defeats the point.
     */
    public function next(\DateTimeInterface $issuedAt, string $key = self::DEFAULT_KEY): string
    {
        return DB::transaction(function () use ($issuedAt, $key) {
            $sequence = $this->sequence($key);

            $locked = InvoiceNumberSequence::whereKey($sequence->getKey())->lockForUpdate()->first();

            $period = $locked->periodKeyFor($issuedAt);

            // A new financial year (or month, or calendar year) restarts the
            // count. The period is recorded so the reset happens once rather
            // than on every invoice of the new period.
            $value = $locked->last_period_key === $period
                ? (int) $locked->current_value + 1
                : 1;

            $locked->forceFill([
                'current_value' => $value,
                'last_period_key' => $period,
            ])->save();

            return $locked->render($value, $issuedAt);
        });
    }

    /** What the next number WOULD be, without consuming it. */
    public function preview(\DateTimeInterface $issuedAt, string $key = self::DEFAULT_KEY): string
    {
        $sequence = $this->sequence($key);
        $period = $sequence->periodKeyFor($issuedAt);

        $value = $sequence->last_period_key === $period ? (int) $sequence->current_value + 1 : 1;

        return $sequence->render($value, $issuedAt);
    }

    public function sequence(string $key = self::DEFAULT_KEY): InvoiceNumberSequence
    {
        // Refreshed on purpose: a row that firstOrCreate has just INSERTED
        // holds only the attributes that were passed, while the database holds
        // the column defaults. Rendering from the in-memory copy would produce
        // a number with no prefix and no padding — and, worse, only for the
        // very first invoice a business ever issues.
        return InvoiceNumberSequence::firstOrCreate(['key' => $key], [])->refresh();
    }
}
