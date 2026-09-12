<?php

namespace App\Domains\Credits\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A pre-authorisation against a balance.
 *
 * Taken BEFORE the provider is called, for an estimate; settled afterwards to
 * what the call actually cost, or released entirely if it failed. The customer
 * pays for answers, never for attempts.
 */
class CreditHold extends Model
{
    public const HELD = 'held';

    public const SETTLED = 'settled';

    public const RELEASED = 'released';

    protected $fillable = [
        'uuid', 'user_id', 'amount', 'status', 'reference_type', 'reference_id',
        'settled_amount', 'expires_at', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:6',
            'settled_amount' => 'decimal:6',
            'expires_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $h) => $h->uuid ??= (string) Str::uuid());
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isOpen(): bool
    {
        return $this->status === self::HELD;
    }

    public function hasExpired(): bool
    {
        return $this->isOpen() && $this->expires_at !== null && $this->expires_at->isPast();
    }
}
