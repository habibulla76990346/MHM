<?php

namespace App\Domains\Payments\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Refund extends Model
{
    public const PENDING = 'pending';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    protected $fillable = [
        'uuid', 'payment_id', 'gateway_id', 'gateway_refund_id', 'amount',
        'currency', 'status', 'reason', 'credits_revoked', 'requested_by', 'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:6',
            'credits_revoked' => 'decimal:6',
            'processed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $r) => $r->uuid ??= (string) Str::uuid());
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
