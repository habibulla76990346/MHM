<?php

namespace App\Domains\Settings\Services;

use App\Domains\Settings\Models\Setting;
use App\Domains\Settings\Support\SettingDefinition;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Reads and writes application settings.
 *
 * PERFORMANCE: the entire table is cached as ONE blob and invalidated on
 * write. Several hundred settings therefore cost one cache read per request,
 * not several hundred queries — which is the difference between a settings
 * system that scales and one that has to be torn out later.
 *
 * SECURITY: settings flagged secret are encrypted at rest and are never
 * included in the public payload handed to the frontend.
 */
class SettingsService
{
    public const CACHE_KEY = 'aziv:settings:all';

    /** Request-local memo, so repeated reads in one request skip even the cache driver. */
    private ?array $memo = null;

    public function __construct(private readonly SettingRegistry $registry)
    {
    }

    public function registry(): SettingRegistry
    {
        return $this->registry;
    }

    /** Read a setting, falling back to its declared default. */
    public function get(string $key, mixed $fallback = null): mixed
    {
        $definition = $this->registry->get($key);

        if (! $definition) {
            // An undeclared key is a programming error, not a missing value.
            throw new InvalidArgumentException("Setting [{$key}] is not declared in the registry.");
        }

        $stored = $this->load();

        if (! array_key_exists($key, $stored)) {
            return $fallback ?? $definition->default;
        }

        $value = $stored[$key];

        if ($definition->isSecret && $value !== null) {
            try {
                $value = Crypt::decryptString($value);
            } catch (\Throwable) {
                // A value that will not decrypt almost always means APP_KEY
                // changed. Returning the ciphertext would be worse than
                // returning the default — see the migration checklist.
                return $definition->default;
            }
        }

        return $definition->cast($value);
    }

    /**
     * Write a setting. Validates against the declared rules, encrypts secrets,
     * busts the cache, and returns the previous value so the caller can audit
     * the before/after (Rule 8).
     */
    public function set(string $key, mixed $value, ?int $actorId = null): mixed
    {
        $definition = $this->registry->get($key);

        if (! $definition) {
            throw new InvalidArgumentException("Setting [{$key}] is not declared in the registry.");
        }

        // Validated under a FLAT field name deliberately. Laravel treats dots
        // in a field name as nested-array access, so validating under the key
        // itself ("auth.password_min_length") would look for $data['auth']
        // ['password_min_length'], find nothing, and pass every rule
        // vacuously — meaning no setting would ever actually be validated.
        $validator = Validator::make(
            ['value' => $value],
            ['value' => $definition->validationRules()],
            [],
            ['value' => $definition->label ?: $key],
        );

        if ($validator->fails()) {
            throw ValidationException::withMessages([
                $key => $validator->errors()->get('value'),
            ]);
        }

        $previous = $this->get($key);
        $serialised = $definition->serialise($value);

        if ($definition->isSecret && $serialised !== null) {
            $serialised = Crypt::encryptString($serialised);
        }

        Setting::updateOrCreate(
            ['key' => $key],
            [
                'value' => $serialised,
                'type' => $definition->type,
                'group' => $definition->group,
                'is_encrypted' => $definition->isSecret,
                'is_public' => $definition->isPublic,
                'updated_by' => $actorId,
            ],
        );

        $this->flush();

        return $previous;
    }

    /** @param array<string, mixed> $values */
    public function setMany(array $values, ?int $actorId = null): array
    {
        $previous = [];

        foreach ($values as $key => $value) {
            $previous[$key] = $this->set($key, $value, $actorId);
        }

        return $previous;
    }

    /**
     * Settings safe to expose to the browser. Secrets can never appear here —
     * the filter is on the declaration, not on the value.
     *
     * @return array<string, mixed>
     */
    public function publicPayload(): array
    {
        $out = [];

        foreach ($this->registry->all() as $key => $definition) {
            if ($definition->isPublic && ! $definition->isSecret) {
                $out[$key] = $this->get($key);
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function group(string $group): array
    {
        $out = [];

        foreach ($this->registry->group($group) as $key => $definition) {
            $out[$key] = $this->get($key);
        }

        return $out;
    }

    public function flush(): void
    {
        $this->memo = null;
        Cache::forget(self::CACHE_KEY);
    }

    /** @return array<string, string|null> raw stored values, keyed by setting key */
    private function load(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        return $this->memo = Cache::rememberForever(
            self::CACHE_KEY,
            fn () => Setting::query()->pluck('value', 'key')->all(),
        );
    }
}
