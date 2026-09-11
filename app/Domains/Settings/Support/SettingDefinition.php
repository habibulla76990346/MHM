<?php

namespace App\Domains\Settings\Support;

/**
 * One declared setting.
 *
 * Every setting is DECLARED before it can be used, with a type, validation
 * rule, default, group and permission. An undeclared key is rejected rather
 * than silently stored — otherwise a typo becomes a permanent phantom setting
 * that nothing reads and nobody can find.
 */
final class SettingDefinition
{
    public function __construct(
        public readonly string $key,
        public readonly string $type = 'string',
        public readonly mixed $default = null,
        public readonly string $group = 'general',
        public readonly string $label = '',
        public readonly string $description = '',
        /** Laravel validation rules applied on write. */
        public readonly array $rules = [],
        /** Permission required to change it. Null means any admin with settings access. */
        public readonly ?string $permission = null,
        /** Secrets are never returned to the frontend and are encrypted at rest. */
        public readonly bool $isSecret = false,
        /** Public settings may be read without authentication (e.g. site name). */
        public readonly bool $isPublic = false,
    ) {
    }

    /** Cast a stored string back to its declared type. */
    public function cast(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($this->type) {
            'bool', 'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'int', 'integer' => (int) $value,
            'float', 'double' => (float) $value,
            'array', 'json' => is_array($value) ? $value : (json_decode((string) $value, true) ?? []),
            default => (string) $value,
        };
    }

    /** Serialise a value for storage. */
    public function serialise(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($this->type) {
            'bool', 'boolean' => $value ? '1' : '0',
            'array', 'json' => json_encode($value),
            default => (string) $value,
        };
    }

    /** Validation rules, with a type-appropriate rule always included. */
    public function validationRules(): array
    {
        if ($this->rules !== []) {
            return $this->rules;
        }

        return match ($this->type) {
            'bool', 'boolean' => ['boolean'],
            'int', 'integer' => ['integer'],
            'float', 'double' => ['numeric'],
            'array', 'json' => ['array'],
            default => ['string', 'max:65535'],
        };
    }
}
