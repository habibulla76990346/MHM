<?php

namespace App\Domains\Tax\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class TaxJurisdiction extends Model
{
    protected $fillable = [
        'uuid', 'name', 'country', 'state', 'is_domestic', 'priority', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_domestic' => 'boolean', 'is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $j) => $j->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function rates(): HasMany
    {
        return $this->hasMany(TaxRate::class, 'jurisdiction_id');
    }

    public function rules(): HasMany
    {
        return $this->hasMany(TaxRule::class, 'jurisdiction_id');
    }

    /**
     * Whether this jurisdiction covers a customer.
     *
     * A jurisdiction with no state covers the whole country; one with a state
     * covers only that state. Priority decides which wins when both match, so
     * a state rule can override a national one without special-casing.
     */
    public function covers(?string $country, ?string $state): bool
    {
        if (strtoupper((string) $country) !== strtoupper((string) $this->country)) {
            return false;
        }

        return blank($this->state)
            || strcasecmp(trim((string) $state), trim((string) $this->state)) === 0;
    }
}
