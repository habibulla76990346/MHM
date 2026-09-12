<?php

namespace App\Domains\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One billing period, activated exactly once.
 *
 * The unique index on (subscription_id, period_start) is the guard: two
 * webhooks for the same payment both try to insert this row and the second
 * fails, so credits are granted once. The check is in the DATABASE rather than
 * in code because two PHP processes can both pass an `exists()` test in the
 * same millisecond.
 */
class SubscriptionPeriod extends Model
{
    protected $fillable = [
        'subscription_id', 'period_start', 'period_end', 'credits_granted',
        'reference', 'activated_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'activated_at' => 'datetime',
            'credits_granted' => 'decimal:6',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }
}
