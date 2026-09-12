<?php

namespace App\Domains\Tax\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * When a set of rates applies.
 *
 * The conditions are structural — "same state", "export" — never a named tax
 * treatment. An administrator decides that same-state means two components and
 * different-state means one; the code only knows how to compare a customer's
 * location to the business's.
 */
class TaxRule extends Model
{
    public const SAME_STATE = 'same_state';

    public const DIFFERENT_STATE = 'different_state';

    public const EXPORT = 'export';

    public const CUSTOMER_REGISTERED = 'customer_registered';

    public const CUSTOMER_UNREGISTERED = 'customer_unregistered';

    public const EXEMPT = 'exempt';

    public const ALWAYS = 'always';

    /** @return array<string, string> */
    public static function conditions(): array
    {
        return [
            self::SAME_STATE => 'Customer is in the same state as the business',
            self::DIFFERENT_STATE => 'Customer is in the same country, a different state',
            self::EXPORT => 'Customer is in another country',
            self::CUSTOMER_REGISTERED => 'Customer has given a tax registration number',
            self::CUSTOMER_UNREGISTERED => 'Customer has no tax registration number',
            self::EXEMPT => 'Customer holds a valid exemption',
            self::ALWAYS => 'Always',
        ];
    }

    protected $fillable = [
        'uuid', 'jurisdiction_id', 'condition_type', 'rate_ids', 'priority', 'is_active',
    ];

    protected function casts(): array
    {
        return ['rate_ids' => 'array', 'is_active' => 'boolean'];
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
}
