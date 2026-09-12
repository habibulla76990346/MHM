<?php

namespace App\Domains\Payments\Models;

use App\Domains\Billing\Models\Invoice;
use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One attempt to take money (§20, Addendum D and F).
 *
 * THREE AMOUNTS, not one. A merchant account in India settles in INR whatever
 * the customer was shown, at the gateway's own rate and minus a cross-border
 * fee — so what the customer agreed to, what landed, and what the owner
 * reports are three different numbers. Conflating them produces books that
 * never balance.
 *
 * BOTH IDENTIFIERS, always: Aziv's uuid and the gateway's own reference.
 * Support has one, the gateway dashboard has the other, and every
 * reconciliation has to move between them.
 */
class Payment extends Model
{
    public const STATUS_CREATED = 'created';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REFUNDED = 'refunded';

    public const STATUS_PARTIALLY_REFUNDED = 'partially_refunded';

    /** A first purchase or a plan change, started from the checkout page. */
    public const PURPOSE_SUBSCRIPTION = 'subscription';

    /** The next period of a subscription that is renewed by invoice. */
    public const PURPOSE_RENEWAL = 'renewal';

    public const PURPOSES = [
        self::PURPOSE_SUBSCRIPTION => 'Subscription',
        self::PURPOSE_RENEWAL => 'Renewal',
    ];

    public function isRenewal(): bool
    {
        return $this->purpose === self::PURPOSE_RENEWAL;
    }

    protected $fillable = [
        'uuid', 'user_id', 'subscription_id', 'plan_id', 'invoice_id', 'gateway_id',
        'gateway_payment_id', 'gateway_order_id', 'mode', 'purpose', 'idempotency_key',
        'presentment_amount', 'presentment_currency', 'settlement_amount',
        'settlement_currency', 'base_amount', 'exchange_rate_used', 'status',
        'failure_code', 'failure_reason', 'paid_at', 'last_checked_at', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'presentment_amount' => 'decimal:6',
            'settlement_amount' => 'decimal:6',
            'base_amount' => 'decimal:6',
            'exchange_rate_used' => 'decimal:8',
            'metadata' => 'array',
            'paid_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $p) => $p->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function gateway(): BelongsTo
    {
        return $this->belongsTo(PaymentGatewayRecord::class, 'gateway_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class, 'payment_id')->orderBy('id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class, 'payment_id');
    }

    public function isPaid(): bool
    {
        return in_array($this->status, [self::STATUS_PAID, self::STATUS_PARTIALLY_REFUNDED], true);
    }

    /** Still waiting for an answer, and old enough that the sweep should ask. */
    public function isStale(int $minutes = 10): bool
    {
        return in_array($this->status, [self::STATUS_CREATED, self::STATUS_PENDING], true)
            && $this->created_at?->lt(now()->subMinutes($minutes));
    }

    public function refundedTotal(): float
    {
        return (float) $this->refunds()->whereIn('status', ['completed', 'pending'])->sum('amount');
    }

    public function refundable(): float
    {
        return max(0.0, (float) $this->presentment_amount - $this->refundedTotal());
    }
}
