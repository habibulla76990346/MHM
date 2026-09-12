<?php

namespace App\Domains\Payments\Models;

use App\Domains\Payments\Support\Capability;
use App\Domains\Payments\Support\CheckoutMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A configured payment gateway (Addendum D §6).
 *
 * Named `PaymentGatewayRecord` rather than `PaymentGateway` because that name
 * belongs to the INTERFACE. Keeping them apart is not pedantry: it is what
 * stops a service that means "the contract" from accidentally type-hinting
 * "the database row", which is how a supposedly gateway-agnostic layer ends up
 * reaching for a column.
 */
class PaymentGatewayRecord extends Model
{
    use SoftDeletes;

    protected $table = 'payment_gateways';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    public const MODE_SANDBOX = 'sandbox';

    public const MODE_LIVE = 'live';

    protected $fillable = [
        'uuid', 'key', 'name', 'adapter_class', 'status', 'is_default', 'priority',
        'mode', 'supported_countries', 'supported_currencies', 'capabilities',
        'checkout_mode', 'maintenance_mode', 'api_base_url', 'timeout_seconds', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'maintenance_mode' => 'boolean',
            'supported_countries' => 'array',
            'supported_currencies' => 'array',
            'capabilities' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $g) => $g->uuid ??= (string) Str::uuid());

        // One default, enforced. Two would make "which gateway?" depend on row
        // order, and the answer would change the day somebody added a third.
        static::saved(function (self $gateway) {
            if ($gateway->is_default) {
                static::where('id', '!=', $gateway->getKey())
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(PaymentGatewayCredential::class, 'gateway_id');
    }

    public function rules(): HasMany
    {
        return $this->hasMany(PaymentGatewayRule::class, 'gateway_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'gateway_id');
    }

    public function webhookEvents(): HasMany
    {
        return $this->hasMany(PaymentWebhookEvent::class, 'gateway_id');
    }

    public function scopeUsable(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE)->where('maintenance_mode', false);
    }

    /** The credential for the mode this gateway is currently running in. */
    public function activeCredential(): ?PaymentGatewayCredential
    {
        return $this->credentials()
            ->where('mode', $this->mode)
            ->where('status', 'active')
            ->first();
    }

    public function isLive(): bool
    {
        return $this->mode === self::MODE_LIVE;
    }

    public function isUsable(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && ! $this->maintenance_mode
            && $this->activeCredential() !== null;
    }

    /**
     * Capabilities as CONFIGURED.
     *
     * The adapter's own declaration is the truth; this column records what was
     * verified against the gateway's documentation when the adapter was
     * written, so the panel can show it without instantiating anything.
     */
    public function declaredCapabilities(): array
    {
        return array_values(array_intersect(
            (array) ($this->capabilities ?? []),
            array_keys(Capability::all()),
        ));
    }

    public function handles(string $currency): bool
    {
        $supported = (array) ($this->supported_currencies ?? []);

        // An empty list means "whatever the merchant account allows" — which
        // is a fact about the owner's agreement, not something software can
        // determine.
        return $supported === [] || in_array(strtoupper($currency), array_map('strtoupper', $supported), true);
    }

    public function servesCountry(?string $country): bool
    {
        $supported = (array) ($this->supported_countries ?? []);

        return $supported === [] || $country === null
            || in_array(strtoupper($country), array_map('strtoupper', $supported), true);
    }

    public function checkoutModeLabel(): string
    {
        return CheckoutMode::all()[$this->checkout_mode] ?? $this->checkout_mode;
    }

    /** Where the owner pastes this into the gateway's own dashboard. */
    public function webhookUrl(): string
    {
        return url('/webhooks/payments/'.$this->key);
    }

    public function stateLabel(): string
    {
        return match (true) {
            $this->status !== self::STATUS_ACTIVE => 'Disabled',
            $this->maintenance_mode => 'In maintenance',
            $this->activeCredential() === null => 'No credentials for '.$this->mode,
            default => ucfirst($this->mode),
        };
    }
}
