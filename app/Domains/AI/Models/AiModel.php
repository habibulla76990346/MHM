<?php

namespace App\Domains\AI\Models;

use App\Domains\AI\Support\Capability;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * One model in the catalog (blueprint §11).
 *
 * Rule 5 in practice: nothing in the application names a model. The router
 * asks this table which models support a capability, and the answer is rows
 * an administrator controls.
 */
class AiModel extends Model
{
    use SoftDeletes;

    public const STATUS_STABLE = 'stable';

    public const STATUS_PREVIEW = 'preview';

    public const STATUS_EXPERIMENTAL = 'experimental';

    public const STATUS_DEPRECATED = 'deprecated';

    public const STATUS_DISABLED = 'disabled';

    public const STATUSES = [
        self::STATUS_STABLE => 'Stable',
        self::STATUS_PREVIEW => 'Preview',
        self::STATUS_EXPERIMENTAL => 'Experimental',
        self::STATUS_DEPRECATED => 'Deprecated',
        self::STATUS_DISABLED => 'Disabled',
    ];

    public const MODALITIES = [
        'text' => 'Text',
        'multimodal' => 'Text and images',
        'image' => 'Image generation',
        'audio' => 'Audio',
        'embedding' => 'Embeddings',
    ];

    public const SOURCE_SYNCED = 'synced';

    public const SOURCE_MANUAL = 'manual';

    protected $fillable = [
        'uuid', 'provider_id', 'model_identifier', 'display_name', 'description',
        'status', 'modality', 'context_window', 'max_output_tokens', 'is_enabled',
        'sort_order', 'quality_rank', 'discovered_at', 'source', 'last_seen_at', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'discovered_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $model) => $model->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'provider_id');
    }

    public function capabilities(): HasMany
    {
        return $this->hasMany(AiModelCapability::class, 'model_id');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(AiModelPrice::class, 'model_id');
    }

    /**
     * Routable right now.
     *
     * Deprecated and disabled models stay in the catalog — a usage record from
     * last month refers to one, and deleting it would orphan that history —
     * but they are never selected for new work.
     */
    public function scopeRoutable(Builder $query): Builder
    {
        return $query->where('is_enabled', true)
            ->whereNotIn('status', [self::STATUS_DEPRECATED, self::STATUS_DISABLED])
            ->whereHas('provider', fn (Builder $q) => $q->usable());
    }

    /** Models that can do all of the given capabilities. */
    public function scopeWithCapabilities(Builder $query, array $capabilities): Builder
    {
        foreach ($capabilities as $capability) {
            $query->whereHas(
                'capabilities',
                fn (Builder $q) => $q->where('capability', $capability)->where('is_supported', true),
            );
        }

        return $query;
    }

    public function supports(string $capability): bool
    {
        return $this->capabilities
            ->firstWhere('capability', $capability)?->is_supported ?? false;
    }

    /** @return array<int, string> */
    public function supportedCapabilities(): array
    {
        return $this->capabilities
            ->where('is_supported', true)
            ->pluck('capability')
            ->all();
    }

    /**
     * The price that applied at a given moment (§13).
     *
     * Never "the current price": costing March's usage at today's rate would
     * silently rewrite the profitability of the whole history.
     */
    public function priceAt(string $unit, ?\DateTimeInterface $moment = null): ?AiModelPrice
    {
        $moment ??= now();

        return $this->prices()
            ->where('unit', $unit)
            ->where('effective_from', '<=', $moment)
            ->where(fn (Builder $q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', $moment))
            ->orderByDesc('effective_from')
            ->first();
    }

    public function isRoutable(): bool
    {
        return $this->is_enabled
            && ! in_array($this->status, [self::STATUS_DEPRECATED, self::STATUS_DISABLED], true)
            && ($this->provider?->isUsable() ?? false);
    }

    public function isLongContext(): bool
    {
        return ($this->context_window ?? 0) >= 100_000;
    }

    /** @return array<int, string> capabilities implied by the declared modality */
    public static function capabilitiesForModality(string $modality): array
    {
        return match ($modality) {
            'multimodal' => [Capability::CHAT, Capability::STREAMING, Capability::VISION],
            'image' => [Capability::IMAGE_GENERATION],
            'audio' => [Capability::TRANSCRIPTION],
            'embedding' => [Capability::EMBEDDINGS],
            default => [Capability::CHAT, Capability::STREAMING],
        };
    }
}
