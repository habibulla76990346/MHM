<?php

namespace App\Domains\Payments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Every state change a payment went through.
 *
 * This is the screen support opens when a customer says "I paid and got
 * nothing": creation, redirect, return, each webhook, each reconciliation
 * attempt, the credit grant. Without it the answer is a guess.
 */
class PaymentTransaction extends Model
{
    public const CREATED = 'created';

    public const REDIRECTED = 'redirected';

    public const RETURNED = 'returned';

    public const WEBHOOK = 'webhook';

    public const RECONCILED = 'reconciled';

    public const REFUNDED = 'refunded';

    protected $fillable = [
        'uuid', 'payment_id', 'gateway_id', 'gateway_transaction_id',
        'gateway_reference', 'type', 'amount', 'currency', 'status', 'raw_payload',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:6', 'raw_payload' => 'array'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $t) => $t->uuid ??= (string) Str::uuid());
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }
}
