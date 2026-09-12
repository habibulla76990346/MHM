<?php

namespace App\Domains\Tax\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The business's own tax identity (Addendum F).
 *
 * A single row rather than settings keys, because every field here is copied
 * onto an invoice AS A GROUP. Half-configured settings would produce an
 * invoice carrying a legal name and no address — worse than no invoice, since
 * it looks finished.
 *
 * Ships DISABLED and empty. No rate, label, code or treatment is assumed
 * anywhere; until an administrator fills this in and switches it on, tax is
 * simply not charged and invoices say so.
 */
class TaxSettings extends Model
{
    protected $table = 'tax_settings';

    public const INCLUSIVE = 'inclusive';

    public const EXCLUSIVE = 'exclusive';

    public const ROUNDING = [
        'half_up' => 'Half up (0.5 rounds away from zero)',
        'half_even' => "Banker's rounding (0.5 rounds to even)",
        'up' => 'Always up',
        'down' => 'Always down',
    ];

    protected $fillable = [
        'legal_name', 'address_lines', 'city', 'state', 'postal_code', 'country',
        'tax_registration_number', 'registration_type', 'default_place_of_supply',
        'service_code', 'tax_enabled', 'pricing_mode', 'rounding_mode', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['tax_enabled' => 'boolean'];
    }

    /**
     * The one row, created empty on first read so a form always has a subject.
     *
     * Refreshed after creation: a just-inserted row holds only what was
     * passed, while the database holds the column defaults — so without this
     * the very first read would see a null pricing mode and rounding rule.
     */
    public static function current(): self
    {
        return static::query()->firstOrCreate([], [])->refresh();
    }

    public function isInclusive(): bool
    {
        return $this->pricing_mode === self::INCLUSIVE;
    }

    /**
     * Whether tax can actually be computed.
     *
     * Enabled is not the same as configured: a business that ticked the box but
     * never entered a legal name would issue invoices that fail an audit. Both
     * are required before a single rate is applied.
     */
    public function isUsable(): bool
    {
        return $this->tax_enabled && filled($this->legal_name) && filled($this->country);
    }

    /** @return array<int, string> what still has to be filled in */
    public function missingFields(): array
    {
        $missing = [];

        foreach ([
            'legal_name' => 'Registered business name',
            'address_lines' => 'Business address',
            'country' => 'Country',
            'tax_registration_number' => 'Tax registration number',
        ] as $field => $label) {
            if (blank($this->{$field})) {
                $missing[] = $label;
            }
        }

        return $missing;
    }

    public function addressBlock(): string
    {
        return collect([
            $this->address_lines,
            trim(implode(' ', array_filter([$this->city, $this->state, $this->postal_code]))),
            $this->country,
        ])->filter()->implode("\n");
    }
}
