<?php

namespace App\Domains\Tax\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerTaxProfile extends Model
{
    protected $fillable = [
        'user_id', 'country', 'state', 'billing_address', 'billing_name',
        'postal_code', 'tax_registration_number', 'registration_verified_at',
        'is_business', 'exemption_reference', 'exemption_expires_at',
    ];

    protected function casts(): array
    {
        return [
            'is_business' => 'boolean',
            'registration_verified_at' => 'datetime',
            'exemption_expires_at' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isRegistered(): bool
    {
        return filled($this->tax_registration_number);
    }

    /**
     * An exemption only counts while it is in date.
     *
     * An expired certificate that still exempted a customer would understate
     * tax on every invoice after it lapsed — and the business, not the
     * customer, owes that money.
     */
    public function isExempt(): bool
    {
        return filled($this->exemption_reference)
            && ($this->exemption_expires_at === null || $this->exemption_expires_at->isFuture());
    }
}
