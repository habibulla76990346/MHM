<?php

namespace App\Domains\Payments\Models;

use App\Domains\Billing\Models\Plan;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which gateway handles what (Addendum D §7).
 *
 * An owner's routing preference, narrowed by payment type, country, currency
 * or plan. It ORDERS the candidates; it never overrides capability — a rule
 * cannot send a subscription to a gateway that cannot renew one.
 */
class PaymentGatewayRule extends Model
{
    protected $fillable = [
        'gateway_id', 'payment_type', 'country', 'currency', 'plan_id',
        'priority', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function gateway(): BelongsTo
    {
        return $this->belongsTo(PaymentGatewayRecord::class, 'gateway_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    /** Does this rule describe the request in front of us? */
    public function matches(string $paymentType, ?string $country, string $currency, ?int $planId): bool
    {
        if (! $this->is_active || $this->payment_type !== $paymentType) {
            return false;
        }

        // A null on the rule means "any" — a rule that names nothing applies
        // to everything of its type, which is the least surprising reading.
        if ($this->country !== null && strtoupper($this->country) !== strtoupper((string) $country)) {
            return false;
        }

        if ($this->currency !== null && strtoupper($this->currency) !== strtoupper($currency)) {
            return false;
        }

        return $this->plan_id === null || $this->plan_id === $planId;
    }
}
