<?php

namespace App\Domains\Tax\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One tax component, named by the administrator.
 *
 * Nothing in this file knows what a component is called or what it is worth.
 * "CGST", "SGST", "IGST", "VAT", 18, 9 — all of it is data an admin typed, and
 * that is the whole of Addendum F's Rule 1.
 */
class TaxRate extends Model
{
    protected $fillable = [
        'uuid', 'jurisdiction_id', 'name', 'code', 'rate_percent',
        'component_type', 'applies_to', 'effective_from', 'effective_until', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'rate_percent' => 'decimal:5',
            'effective_from' => 'date',
            'effective_until' => 'date',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $r) => $r->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function jurisdiction(): BelongsTo
    {
        return $this->belongsTo(TaxJurisdiction::class, 'jurisdiction_id');
    }

    /**
     * Rates in force on a given DATE — never "today".
     *
     * Reissuing a March invoice must produce the March document. Selecting by
     * today's date is the mistake that makes every historical invoice change
     * the day a rate does.
     */
    public function scopeEffectiveOn(Builder $query, \DateTimeInterface $date): Builder
    {
        return $query->where('is_active', true)
            ->whereDate('effective_from', '<=', $date)
            ->where(fn (Builder $q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $date));
    }

    public function appliesTo(string $subject): bool
    {
        return $this->applies_to === 'all' || $this->applies_to === $subject;
    }
}
