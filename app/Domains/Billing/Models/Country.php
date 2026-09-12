<?php

namespace App\Domains\Billing\Models;

use App\Domains\Tax\Models\TaxJurisdiction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A country the platform will bill in.
 *
 * `requires_state` exists because place of supply is a state-level concept in
 * India and meaningless in most of Europe. Asking every customer for a state
 * would be wrong; never asking would make an Indian invoice non-compliant.
 */
class Country extends Model
{
    protected $fillable = [
        'code', 'name', 'default_currency', 'requires_state',
        'is_billing_enabled', 'tax_jurisdiction_id',
    ];

    protected function casts(): array
    {
        return ['requires_state' => 'boolean', 'is_billing_enabled' => 'boolean'];
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    public function jurisdiction(): BelongsTo
    {
        return $this->belongsTo(TaxJurisdiction::class, 'tax_jurisdiction_id');
    }
}
