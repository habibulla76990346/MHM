<?php

namespace App\Domains\Settings\Services;

use App\Domains\Settings\Support\SettingDefinition;

/**
 * The declared settings catalogue.
 *
 * Phase 1 declares the identity, security and system settings it needs. Each
 * later phase appends its own group — branding and themes in Phase 2, AI
 * routing defaults in Phase 3/5, billing and tax in Phase 6 — which is how
 * blueprint Rule 4 ("admin-configurable wherever practical") stays true as
 * the platform grows rather than decaying into hard-coded values.
 */
class SettingRegistry
{
    /** @var array<string, SettingDefinition> */
    private array $definitions = [];

    public function __construct()
    {
        $this->registerMany($this->coreDefinitions());
    }

    public function register(SettingDefinition $definition): void
    {
        $this->definitions[$definition->key] = $definition;
    }

    /** @param iterable<SettingDefinition> $definitions */
    public function registerMany(iterable $definitions): void
    {
        foreach ($definitions as $definition) {
            $this->register($definition);
        }
    }

    public function get(string $key): ?SettingDefinition
    {
        return $this->definitions[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->definitions[$key]);
    }

    /** @return array<string, SettingDefinition> */
    public function all(): array
    {
        return $this->definitions;
    }

    /** @return array<string, SettingDefinition> */
    public function group(string $group): array
    {
        return array_filter($this->definitions, fn (SettingDefinition $d) => $d->group === $group);
    }

    /** @return array<int, string> */
    public function groups(): array
    {
        return array_values(array_unique(array_map(fn (SettingDefinition $d) => $d->group, $this->definitions)));
    }

    /** @return array<int, SettingDefinition> */
    private function coreDefinitions(): array
    {
        return [
            // --- Identity & registration (blueprint §8) ----------------------
            new SettingDefinition(
                key: 'auth.registration_enabled', type: 'bool', default: true, group: 'auth',
                label: 'Allow new registrations',
                description: 'Turn off to stop new signups without affecting existing users.',
            ),
            new SettingDefinition(
                key: 'auth.require_email_verification', type: 'bool', default: true, group: 'auth',
                label: 'Require email verification',
                description: 'New accounts must confirm their email address before using the platform.',
            ),
            new SettingDefinition(
                key: 'auth.password_min_length', type: 'int', default: 12, group: 'auth',
                label: 'Minimum password length',
                rules: ['integer', 'min:8', 'max:128'],
            ),
            new SettingDefinition(
                key: 'auth.password_require_mixed_case', type: 'bool', default: true, group: 'auth',
                label: 'Require upper and lower case',
            ),
            new SettingDefinition(
                key: 'auth.password_require_number', type: 'bool', default: true, group: 'auth',
                label: 'Require a number',
            ),
            new SettingDefinition(
                key: 'auth.password_require_symbol', type: 'bool', default: false, group: 'auth',
                label: 'Require a symbol',
            ),
            new SettingDefinition(
                key: 'auth.max_concurrent_sessions', type: 'int', default: 5, group: 'auth',
                label: 'Maximum simultaneous sessions per user',
                description: 'Oldest sessions are signed out when the limit is exceeded. 0 means no limit.',
                rules: ['integer', 'min:0', 'max:100'],
            ),
            new SettingDefinition(
                key: 'auth.idle_timeout_minutes', type: 'int', default: 0, group: 'auth',
                label: 'Sign out after inactivity (minutes)',
                description: '0 disables the idle timeout.',
                rules: ['integer', 'min:0', 'max:20160'],
            ),

            // --- Branding text (blueprint §4) — assets arrive in Phase 2 -----
            new SettingDefinition(
                key: 'branding.app_name', type: 'string', default: 'Aziv AI', group: 'branding',
                label: 'Product name', isPublic: true,
                rules: ['string', 'max:120'],
            ),
            new SettingDefinition(
                key: 'branding.short_name', type: 'string', default: 'Aziv', group: 'branding',
                label: 'Short name', isPublic: true,
                description: 'Used where space is tight, and as the PWA short name.',
                rules: ['string', 'max:32'],
            ),
            new SettingDefinition(
                key: 'branding.tagline', type: 'string', default: 'Multi-provider AI platform',
                group: 'branding', label: 'Tagline', isPublic: true,
                rules: ['string', 'max:200'],
            ),
            new SettingDefinition(
                key: 'branding.support_email', type: 'string', default: null, group: 'branding',
                label: 'Support email', isPublic: true,
                rules: ['nullable', 'email', 'max:190'],
            ),

            // --- System (blueprint §24) --------------------------------------
            new SettingDefinition(
                key: 'system.maintenance_mode', type: 'bool', default: false, group: 'system',
                label: 'Maintenance mode',
                description: 'Visitors see the maintenance page. Administrators keep access.',
                permission: 'settings.system.update',
            ),
            new SettingDefinition(
                key: 'system.maintenance_message', type: 'string',
                default: 'We are carrying out scheduled maintenance and will be back shortly.',
                group: 'system', label: 'Maintenance message',
                rules: ['string', 'max:2000'],
            ),
            new SettingDefinition(
                key: 'system.default_locale', type: 'string', default: 'en', group: 'system',
                label: 'Default language', isPublic: true,
                rules: ['string', 'max:10'],
            ),
            new SettingDefinition(
                key: 'system.default_timezone', type: 'string', default: 'UTC', group: 'system',
                label: 'Default timezone',
                rules: ['string', 'timezone'],
            ),
            new SettingDefinition(
                key: 'system.default_currency', type: 'string', default: 'INR', group: 'system',
                label: 'Default currency',
                description: 'Decision D-01: INR is the initial primary currency; more are added in Phase 6.',
                rules: ['string', 'size:3'],
            ),

            // --- Interface defaults (blueprint §6) ---------------------------
            new SettingDefinition(
                key: 'ui.pagination_default', type: 'int', default: 25, group: 'ui',
                label: 'Rows per page',
                rules: ['integer', 'min:5', 'max:200'],
            ),
            new SettingDefinition(
                key: 'ui.table_density', type: 'string', default: 'normal', group: 'ui',
                label: 'Table density',
                rules: ['string', 'in:compact,normal,relaxed'],
            ),
            new SettingDefinition(
                key: 'ui.date_format', type: 'string', default: 'd M Y', group: 'ui',
                label: 'Date format',
                rules: ['string', 'max:32'],
            ),
            new SettingDefinition(
                key: 'ui.theme_mode', type: 'string', default: 'system', group: 'ui',
                label: 'Default colour mode', isPublic: true,
                description: 'system follows the visitor\'s device; light and dark force one.',
                rules: ['string', 'in:system,light,dark'],
            ),

            // --- Uploads (Owner Addendum H) ----------------------------------
            new SettingDefinition(
                key: 'uploads.max_size_kb', type: 'int', default: 10240, group: 'uploads',
                label: 'Maximum upload size (KB)',
                description: 'Aziv AI also respects the server\'s own limit, whichever is lower.',
                rules: ['integer', 'min:64', 'max:512000'],
            ),
            new SettingDefinition(
                key: 'uploads.allowed_extensions', type: 'array',
                default: ['jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf', 'txt', 'md', 'csv', 'docx', 'xlsx'],
                group: 'uploads', label: 'Allowed file types',
                description: 'An allowlist. Anything not listed is rejected.',
            ),
            new SettingDefinition(
                key: 'uploads.scanner', type: 'string', default: 'none', group: 'uploads',
                label: 'Malware scanner',
                description: 'none, clamav or api. With none, Aziv AI is not scanning — diagnostics report this rather than implying it is.',
                rules: ['string', 'in:none,clamav,api'],
            ),
        ];
    }
}
