<?php

namespace App\Domains\Billing\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CouponRedemption extends Model
{
    protected $fillable = [
        'coupon_id', 'user_id', 'reference_type', 'reference_id',
        'amount_discounted', 'redeemed_at',
    ];

    protected function casts(): array
    {
        return ['amount_discounted' => 'decimal:6', 'redeemed_at' => 'datetime'];
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class, 'coupon_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
