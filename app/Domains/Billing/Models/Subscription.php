<?php

namespace App\Domains\Billing\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Subscription extends Model
{
    public const STATUS_TRIALING = 'trialing';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAST_DUE = 'past_due';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    /**
     * How a period is paid for next time.
     *
     * Not one mechanism: Indian recurring payments run through a gateway's own
     * subscription API, a UPI Autopay mandate, e-NACH, or an invoice the
     * customer pays by hand. Which one is recorded, never assumed.
     */
    public const MECHANISMS = [
        'manual' => 'Invoice and pay each period',
        'gateway_subscription' => 'The gateway bills it automatically',
        'mandate' => 'Auto-debit under a mandate',
        'none' => 'Never renews',
    ];

    protected $fillable = [
        'uuid', 'user_id', 'plan_id', 'status', 'current_period_start',
        'current_period_end', 'trial_ends_at', 'cancel_at', 'cancelled_at',
        'ended_at', 'currency', 'amount', 'renewal_mechanism', 'mandate_reference',
        'pending_plan_id', 'pending_plan_starts_at',
    ];

    protected function casts(): array
    {
        return [
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'trial_ends_at' => 'datetime',
            'cancel_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'ended_at' => 'datetime',
            'pending_plan_starts_at' => 'datetime',
            'amount' => 'decimal:6',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $s) => $s->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    /** The plan a scheduled downgrade will move to when this period ends. */
    public function pendingPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'pending_plan_id');
    }

    public function periods(): HasMany
    {
        return $this->hasMany(SubscriptionPeriod::class, 'subscription_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'subscription_id');
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_ACTIVE, self::STATUS_TRIALING]);
    }

    /**
     * Whether this subscription entitles its owner to anything right now.
     *
     * A CANCELLED subscription is still live until its period ends: the
     * customer paid for that time. Cancelling is not a refund, and cutting
     * them off early would be taking money for nothing.
     */
    public function isLive(): bool
    {
        if (in_array($this->status, [self::STATUS_EXPIRED], true)) {
            return false;
        }

        if ($this->status === self::STATUS_CANCELLED) {
            return $this->current_period_end?->isFuture() ?? false;
        }

        return in_array($this->status, [self::STATUS_ACTIVE, self::STATUS_TRIALING, self::STATUS_PAST_DUE], true);
    }

    public function isOnTrial(): bool
    {
        return $this->status === self::STATUS_TRIALING && ($this->trial_ends_at?->isFuture() ?? false);
    }

    /** How much of the current period is still unused, 0..1. */
    public function unusedFraction(): float
    {
        $start = $this->current_period_start;
        $end = $this->current_period_end;

        if (! $start || ! $end || $end->lte($start)) {
            return 0.0;
        }

        $total = $start->diffInSeconds($end);
        $left = max(0, now()->diffInSeconds($end, false));

        return $total > 0 ? min(1.0, max(0.0, $left / $total)) : 0.0;
    }
}
