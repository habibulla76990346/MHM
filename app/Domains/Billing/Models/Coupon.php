<?php

namespace App\Domains\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Coupon extends Model
{
    public const PERCENTAGE = 'percentage';

    public const FIXED = 'fixed';

    public const CREDITS = 'credits';

    protected $fillable = [
        'uuid', 'code', 'description', 'type', 'value', 'currency',
        'max_redemptions', 'redeemed_count', 'max_per_user', 'valid_from',
        'valid_until', 'plan_restrictions', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:6',
            'plan_restrictions' => 'array',
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $c) {
            $c->uuid ??= (string) Str::uuid();
            $c->code = strtoupper(trim($c->code));
        });
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class, 'coupon_id');
    }

    public function isRedeemable(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->valid_from && $this->valid_from->isFuture()) {
            return false;
        }

        if ($this->valid_until && $this->valid_until->isPast()) {
            return false;
        }

        return $this->max_redemptions === null || $this->redeemed_count < $this->max_redemptions;
    }

    public function appliesToPlan(Plan $plan): bool
    {
        $restrictions = $this->plan_restrictions ?? [];

        // No restriction means every plan. An empty list is "unrestricted",
        // not "nothing" — the opposite reading would silently disable every
        // coupon an owner created without opening the restrictions field.
        return $restrictions === [] || in_array($plan->getKey(), array_map('intval', $restrictions), true);
    }

    /** What this coupon takes off a given amount, never more than the amount. */
    public function discountFor(float $amount): float
    {
        return match ($this->type) {
            self::PERCENTAGE => min($amount, round($amount * (float) $this->value / 100, 6)),
            self::FIXED => min($amount, (float) $this->value),
            default => 0.0,
        };
    }
}
