<?php

namespace App\Domains\AI\Models;

use App\Domains\AI\Routing\CircuitBreaker;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class AiProvider extends Model
{
    use SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    public const STATUSES = [
        self::STATUS_ACTIVE => 'Active',
        self::STATUS_DISABLED => 'Disabled',
    ];

    public const ACCOUNT_CLASSES = [
        'free' => 'Free tier',
        'paid' => 'Paid',
        'enterprise' => 'Enterprise',
        'unknown' => 'Not known',
    ];

    public const AUTH_METHODS = [
        'bearer' => 'Bearer token (Authorization header)',
        'header' => 'Custom header',
        'query' => 'Query string parameter',
    ];

    protected $fillable = [
        'uuid', 'name', 'slug', 'adapter_type', 'api_base_url', 'api_format',
        'auth_method', 'status', 'priority', 'region', 'account_class',
        'maintenance_mode', 'timeout_seconds', 'max_retries', 'settings', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'maintenance_mode' => 'boolean',
            'settings' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $provider) {
            $provider->uuid ??= (string) Str::uuid();
            $provider->slug ??= Str::slug($provider->name);
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(AiProviderCredential::class, 'provider_id')->orderBy('priority');
    }

    public function models(): HasMany
    {
        return $this->hasMany(AiModel::class, 'provider_id');
    }

    public function budgets(): HasMany
    {
        return $this->hasMany(AiProviderBudget::class, 'provider_id');
    }

    public function circuit(): HasOne
    {
        return $this->hasOne(ProviderCircuitState::class, 'provider_id');
    }

    public function healthLogs(): HasMany
    {
        return $this->hasMany(ProviderHealthLog::class, 'provider_id');
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(AiModelSyncLog::class, 'provider_id')->latest('started_at');
    }

    public function mappings(): HasMany
    {
        return $this->hasMany(CustomProviderMapping::class, 'provider_id');
    }

    /**
     * Usable right now.
     *
     * Maintenance mode is separate from disabled on purpose: "temporarily
     * routing around this" and "we do not use this provider" are different
     * decisions, and an owner switching one must not lose the other.
     */
    public function scopeUsable(Builder $query): Builder
    {
        // Qualified for the same reason AiModel::routable() is: ai_models also
        // has a `status`, and these scopes are combined in one query.
        return $query->where('ai_providers.status', self::STATUS_ACTIVE)
            ->where('ai_providers.maintenance_mode', false);
    }

    public function isUsable(): bool
    {
        return $this->status === self::STATUS_ACTIVE && ! $this->maintenance_mode;
    }

    /** The credential to use, honouring priority and skipping dead keys. */
    public function activeCredential(): ?AiProviderCredential
    {
        return $this->credentials()
            ->where('status', AiProviderCredential::STATUS_ACTIVE)
            ->orderBy('priority')
            ->first();
    }

    public function stateLabel(): string
    {
        return match (true) {
            $this->status !== self::STATUS_ACTIVE => 'Disabled',
            $this->maintenance_mode => 'In maintenance',
            // The live breaker, not the mirror row: the mirror is written
            // best-effort and can lag, and a provider shown as healthy while
            // the router is skipping it is the one thing this label must
            // never say.
            app(CircuitBreaker::class)->isOpen($this) => 'Circuit open',
            ! $this->credentials()->where('status', 'active')->exists() => 'No key',
            default => 'Active',
        };
    }
}
