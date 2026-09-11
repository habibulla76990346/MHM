<?php

namespace App\Domains\Content\Services;

use App\Domains\Content\Models\FeatureFlag;
use Illuminate\Support\Facades\Cache;

/**
 * Feature flags (blueprint §6, §24).
 *
 * Read on nearly every request, so the whole set is cached as ONE plain array
 * — a flag lookup must never be a query, and the cache must never hold model
 * objects.
 *
 * A flag that has never been declared reads as OFF. Failing closed means a
 * typo in a flag key hides a feature rather than exposing an unfinished one.
 */
class FeatureFlagService
{
    private const CACHE_KEY = 'aziv:feature_flags';

    /** @var array<string, bool>|null */
    private ?array $resolved = null;

    public function enabled(string $key): bool
    {
        return $this->all()[$key] ?? false;
    }

    /** @return array<string, bool> */
    public function all(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        try {
            return $this->resolved = Cache::rememberForever(
                self::CACHE_KEY,
                fn () => FeatureFlag::pluck('is_enabled', 'key')
                    ->map(fn ($v) => (bool) $v)
                    ->all(),
            );
        } catch (\Throwable) {
            // A database that is not migrated yet must not take the site down;
            // every flag simply reads as off. The diagnostics layer reports the
            // underlying fault with its real reason.
            return $this->resolved = [];
        }
    }

    public function set(string $key, bool $enabled, ?int $actorId = null): FeatureFlag
    {
        $flag = FeatureFlag::firstOrNew(['key' => $key]);

        $flag->fill([
            'name' => $flag->name ?: str($key)->replace(['_', '.'], ' ')->title()->toString(),
            'is_enabled' => $enabled,
            'updated_by' => $actorId,
        ])->save();

        $this->flush();

        return $flag;
    }

    public function flush(): void
    {
        $this->resolved = null;
        Cache::forget(self::CACHE_KEY);
    }
}
