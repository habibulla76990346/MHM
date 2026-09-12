<?php

namespace App\Domains\Credits\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The cached running total.
 *
 * A cache, not the truth — the ledger is the truth. It exists because summing
 * every row a customer has ever generated on each request would get slower for
 * the life of the account. It is updated inside the same transaction as the
 * ledger row, so the two cannot drift.
 */
class CreditBalance extends Model
{
    protected $fillable = ['user_id', 'confirmed_balance', 'held_balance'];

    protected function casts(): array
    {
        return ['confirmed_balance' => 'decimal:6', 'held_balance' => 'decimal:6'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * What can actually be spent right now.
     *
     * Confirmed minus held: money promised to calls already in flight is not
     * available to promise again, which is what stops ten simultaneous
     * requests all passing an affordability check against the same balance.
     */
    public function spendable(): float
    {
        return (float) $this->confirmed_balance - (float) $this->held_balance;
    }
}
