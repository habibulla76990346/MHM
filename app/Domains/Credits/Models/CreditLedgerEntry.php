<?php

namespace App\Domains\Credits\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * One movement of credit (§19).
 *
 * APPEND-ONLY, enforced here and not merely intended. A ledger whose rows can
 * be edited is not a ledger — it cannot answer "where did this balance come
 * from?" and it cannot be shown to anyone who needs to trust it. Corrections
 * are new rows with a reason.
 *
 * `amount` is SIGNED: a deduction is negative. That makes the invariant a sum
 * — the ledger total for a user must always equal their cached balance — which
 * is the single check that proves the whole subsystem is behaving.
 */
class CreditLedgerEntry extends Model
{
    protected $table = 'credit_ledger';

    public const GRANT = 'grant';

    public const DEDUCTION = 'deduction';

    public const REFUND = 'refund';

    public const ADJUSTMENT = 'adjustment';

    public const EXPIRY = 'expiry';

    /** Credits taken back when a payment is refunded. */
    public const REVOCATION = 'revocation';

    protected $fillable = [
        'uuid', 'user_id', 'entry_type', 'amount', 'balance_after', 'reason',
        'reference_type', 'reference_id', 'expires_at', 'actor_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:6',
            'balance_after' => 'decimal:6',
            'expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $e) => $e->uuid ??= (string) Str::uuid());

        // The append-only guarantee, made real. Both throw rather than return
        // false: a silent no-op would let a caller believe it had corrected
        // something.
        static::updating(function () {
            throw new RuntimeException('The credit ledger is append-only. Write a correcting entry instead.');
        });

        static::deleting(function () {
            throw new RuntimeException('The credit ledger is append-only. Ledger entries are never deleted.');
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function isCredit(): bool
    {
        return (float) $this->amount > 0;
    }
}
