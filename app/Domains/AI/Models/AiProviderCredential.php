<?php

namespace App\Domains\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * An API key (blueprint §10, Rule 6).
 *
 * THE SECURITY MODEL, in full:
 *
 *  1. `credential` is encrypted at rest with APP_KEY. It is `$hidden`, so it
 *     cannot reach a JSON response even by accident — a model serialised into
 *     an API payload or a Livewire snapshot simply does not contain it.
 *  2. `hint` holds the last four characters in plaintext, written once on
 *     save. The Admin Panel identifies a key from THIS, so displaying the list
 *     never decrypts anything (§25: the full secret is never shown).
 *  3. Reading the real value is a deliberate call to `secret()`, which exists
 *     in one place and is easy to audit.
 *
 * ON MULTIPLE KEYS (Rule 7). Several credentials are supported for genuine
 * rotation and redundancy — separate billing accounts, a standby for a revoked
 * key, regional accounts. Aziv AI does NOT cycle keys when one hits its quota:
 * that behaviour has no purpose other than evading the limits a provider set,
 * which Rule 7 forbids. `credential_usage_counters` exists so limits can be
 * respected, not routed around.
 */
class AiProviderCredential extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    public const STATUS_REVOKED = 'revoked';

    public const STATUSES = [
        self::STATUS_ACTIVE => 'In use',
        self::STATUS_DISABLED => 'Switched off',
        self::STATUS_REVOKED => 'Revoked by the provider',
    ];

    protected $fillable = [
        'uuid', 'provider_id', 'label', 'credential', 'extra_config', 'status',
        'priority', 'quota_note', 'updated_by',
    ];

    /**
     * Never serialised. This is the control that makes "no credential value
     * reaches a response body" structural rather than a habit — every
     * toArray(), toJson(), API resource and Livewire snapshot omits it.
     */
    protected $hidden = ['credential', 'extra_config'];

    protected function casts(): array
    {
        return [
            'credential' => 'encrypted',
            'extra_config' => 'encrypted:array',
            'last_used_at' => 'datetime',
            'last_verified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $c) => $c->uuid ??= (string) Str::uuid());

        // The hint is derived on every write, so it can never describe a key
        // that has since been replaced.
        static::saving(function (self $credential) {
            if ($credential->isDirty('credential')) {
                $credential->hint = self::hintFor($credential->getAttribute('credential'));
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'provider_id');
    }

    public function usageCounters(): HasMany
    {
        return $this->hasMany(CredentialUsageCounter::class, 'credential_id');
    }

    /**
     * The real key. The ONLY place it is read back.
     *
     * Deliberately a method rather than an attribute: `$credential->secret()`
     * reads as an action in a diff, where `$credential->credential` would look
     * like any other property access.
     */
    public function secret(): string
    {
        return (string) $this->getAttribute('credential');
    }

    /** @return array<string, mixed> */
    public function extra(): array
    {
        return (array) ($this->getAttribute('extra_config') ?? []);
    }

    /**
     * What an administrator sees. Never more than this (§25).
     */
    public function masked(): string
    {
        return $this->hint ? '••••••••'.$this->hint : '••••••••';
    }

    public static function hintFor(?string $secret): ?string
    {
        $secret = trim((string) $secret);

        // A short string is not a key, and showing the last 4 of a 6-character
        // value would reveal most of it.
        return strlen($secret) >= 12 ? substr($secret, -4) : null;
    }

    public function isUsable(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
