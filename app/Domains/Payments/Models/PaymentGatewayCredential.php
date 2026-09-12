<?php

namespace App\Domains\Payments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A gateway's credentials, per mode (Addendum D §5).
 *
 * The same security model as an AI provider key, with one addition that
 * matters and is easy to get backwards:
 *
 *   SECRET / API KEY      encrypted, $hidden, server-side only. Full account
 *                         access. NEVER rendered.
 *   WEBHOOK SECRET        same. Signing key.
 *   PUBLISHABLE KEY       plaintext, rendered into the checkout page BY
 *                         DESIGN. It identifies the merchant to the gateway's
 *                         own JavaScript and authorises nothing on its own.
 *
 * Treating the publishable key as a secret breaks checkout; treating the
 * secret as publishable hands over the account. They are separate columns so
 * the distinction cannot be lost.
 *
 * SANDBOX AND LIVE ARE SEPARATE ROWS. Switching modes therefore cannot pick up
 * the wrong key, and testing in production cannot take real money by mistake.
 */
class PaymentGatewayCredential extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    protected $fillable = [
        'uuid', 'gateway_id', 'mode', 'label', 'credentials', 'webhook_secret',
        'publishable_key', 'hint', 'status', 'last_verified_at', 'verified_by',
    ];

    /**
     * Never serialised. The control that makes "no secret reaches a response
     * body" structural rather than a habit: every toArray(), toJson(), API
     * resource and Livewire snapshot omits these.
     */
    protected $hidden = ['credentials', 'webhook_secret'];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'webhook_secret' => 'encrypted',
            'last_verified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $c) => $c->uuid ??= (string) Str::uuid());

        // The hint is written from the secret ONCE, on save, so the panel can
        // say which key is in place without ever decrypting one to display it.
        static::saving(function (self $credential) {
            if ($credential->isDirty('credentials')) {
                $primary = $credential->primarySecret();

                $credential->hint = $primary === null || strlen($primary) < 4
                    ? null
                    : '…'.substr($primary, -4);
            }
        });
    }

    public function gateway(): BelongsTo
    {
        return $this->belongsTo(PaymentGatewayRecord::class, 'gateway_id');
    }

    /**
     * The decrypted credential bag.
     *
     * ONE READ PATH, so there is one thing to audit. Adapters call this;
     * nothing else should.
     *
     * @return array<string, string>
     */
    public function secret(): array
    {
        return (array) ($this->credentials ?? []);
    }

    public function secretValue(string $key, ?string $default = null): ?string
    {
        $value = $this->secret()[$key] ?? $default;

        return $value === null ? null : (string) $value;
    }

    /** The signing key, read only where a signature is being checked. */
    public function signingSecret(): ?string
    {
        return $this->webhook_secret;
    }

    /**
     * The value the hint is derived from.
     *
     * Whichever field a gateway calls its main secret — adapters differ, and
     * the bag is deliberately free-form so a gateway with unusual fields needs
     * no migration.
     */
    private function primarySecret(): ?string
    {
        $bag = (array) ($this->credentials ?? []);

        foreach (['key_secret', 'secret', 'api_key', 'salt', 'merchant_secret'] as $field) {
            if (filled($bag[$field] ?? null)) {
                return (string) $bag[$field];
            }
        }

        $first = reset($bag);

        return is_string($first) && $first !== '' ? $first : null;
    }

    public function isVerified(): bool
    {
        return $this->last_verified_at !== null;
    }
}
