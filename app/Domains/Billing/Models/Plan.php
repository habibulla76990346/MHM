<?php

namespace App\Domains\Billing\Models;

use App\Domains\AI\Models\AiModel;
use App\Domains\AI\Models\AiProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A subscription plan (§19).
 *
 * FREE, PRO and PREMIUM are not in this file, and that is the point: they are
 * three rows an administrator creates, renames or deletes. Everything the
 * blueprint lists as configurable — price, currency, cycle, messages, tokens,
 * image credits, file limits, storage, models, providers and features — is a
 * row in `plan_features`, `plan_prices` or the access tables.
 */
class Plan extends Model
{
    use SoftDeletes;

    protected $table = 'subscription_plans';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    public const CYCLES = [
        'none' => 'No renewal (free)',
        'monthly' => 'Every month',
        'yearly' => 'Every year',
        'lifetime' => 'One payment, forever',
    ];

    protected $fillable = [
        'uuid', 'name', 'slug', 'description', 'highlights', 'billing_cycle',
        'trial_days', 'credits_per_period', 'credits_rollover', 'credit_expiry_days',
        'is_free', 'is_default', 'is_public', 'status', 'sort_order', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'credits_per_period' => 'decimal:6',
            'credits_rollover' => 'boolean',
            'is_free' => 'boolean',
            'is_default' => 'boolean',
            'is_public' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $plan) {
            $plan->uuid ??= (string) Str::uuid();
            $plan->slug ??= Str::slug($plan->name);
        });

        // Exactly one default. Two would make "which plan does a new account
        // get?" depend on row order.
        static::saved(function (self $plan) {
            if ($plan->is_default) {
                static::where('id', '!=', $plan->getKey())
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function features(): HasMany
    {
        return $this->hasMany(PlanFeature::class, 'plan_id');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(PlanPrice::class, 'plan_id');
    }

    public function modelAccess(): HasMany
    {
        return $this->hasMany(PlanModelAccess::class, 'plan_id');
    }

    public function providerAccess(): HasMany
    {
        return $this->hasMany(PlanProviderAccess::class, 'plan_id');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'plan_id');
    }

    public function scopePurchasable(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE)->where('is_public', true);
    }

    /** The plan a brand-new account lands on. */
    public static function default(): ?self
    {
        return static::where('is_default', true)->where('status', self::STATUS_ACTIVE)->first();
    }

    public function feature(string $key): ?PlanFeature
    {
        return $this->features->firstWhere('key', $key);
    }

    public function priceIn(string $currency): ?PlanPrice
    {
        return $this->prices->first(
            fn (PlanPrice $p) => $p->is_active && strtoupper($p->currency) === strtoupper($currency),
        );
    }

    /**
     * Whether this plan may use a model.
     *
     * ABSENCE OF A ROW MEANS ALLOWED. An owner who has never opened the access
     * screen has a working plan rather than one that can reach nothing — and a
     * newly synced model does not silently become unavailable on every plan.
     * Denial is always explicit.
     */
    public function allowsModel(AiModel $model): bool
    {
        $row = $this->modelAccess->firstWhere('ai_model_id', $model->getKey());

        if ($row && ! $row->is_allowed) {
            return false;
        }

        $provider = $this->providerAccess->firstWhere('ai_provider_id', $model->provider_id);

        return ! ($provider && ! $provider->is_allowed);
    }

    public function allowsProvider(AiProvider $provider): bool
    {
        $row = $this->providerAccess->firstWhere('ai_provider_id', $provider->getKey());

        return ! ($row && ! $row->is_allowed);
    }

    /** @return array<int, int> model ids this plan may NOT use */
    public function deniedModelIds(): array
    {
        $denied = $this->modelAccess->where('is_allowed', false)->pluck('ai_model_id')->all();

        $deniedProviders = $this->providerAccess->where('is_allowed', false)->pluck('ai_provider_id');

        if ($deniedProviders->isNotEmpty()) {
            $denied = array_merge(
                $denied,
                AiModel::whereIn('provider_id', $deniedProviders)->pluck('id')->all(),
            );
        }

        return array_values(array_unique(array_map('intval', $denied)));
    }
}
