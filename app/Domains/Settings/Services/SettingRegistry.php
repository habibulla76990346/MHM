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
                key: 'auth.mfa_required_for_admins', type: 'bool', default: false, group: 'auth',
                label: 'Require a second factor for anybody with admin access',
                description: 'A six-digit code from an authenticator app, on top of the password. OFF by default on purpose — turning it on before you have set it up on your own account and saved the recovery codes is how somebody locks themselves out of their own platform. Customers are never asked for it.',
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
            new SettingDefinition(
                key: 'branding.footer_text', type: 'string', default: null, group: 'branding',
                label: 'Footer line', isPublic: true,
                description: 'Shown at the foot of customer pages. Leave empty for a plain copyright line.',
                rules: ['nullable', 'string', 'max:300'],
            ),

            // --- Brand assets (owner decisions D-09 and D-13) ----------------
            // Each holds a web-root-relative path written by BrandAssetPublisher,
            // or null to use the artwork that ships with Aziv AI. Never a URL:
            // a full URL here would let branding point at a third party.
            new SettingDefinition(
                key: 'branding.asset_logo_light', type: 'string', default: null, group: 'branding',
                label: 'Logo for light backgrounds', isPublic: true,
                rules: ['nullable', 'string', 'max:190', 'starts_with:brand/'],
            ),
            new SettingDefinition(
                key: 'branding.asset_logo_dark', type: 'string', default: null, group: 'branding',
                label: 'Logo for dark backgrounds', isPublic: true,
                rules: ['nullable', 'string', 'max:190', 'starts_with:brand/'],
            ),
            new SettingDefinition(
                key: 'branding.asset_mark', type: 'string', default: null, group: 'branding',
                label: 'Compact mark', isPublic: true,
                rules: ['nullable', 'string', 'max:190', 'starts_with:brand/'],
            ),
            new SettingDefinition(
                key: 'branding.asset_favicon', type: 'string', default: null, group: 'branding',
                label: 'Favicon', isPublic: true,
                rules: ['nullable', 'string', 'max:190', 'starts_with:brand/'],
            ),
            new SettingDefinition(
                key: 'branding.asset_app_icon', type: 'string', default: null, group: 'branding',
                label: 'App icon', isPublic: true,
                rules: ['nullable', 'string', 'max:190', 'starts_with:brand/'],
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

            // --- Theme (owner decision D-07) ---------------------------------
            new SettingDefinition(
                key: 'theme.previous_theme_id', type: 'string', default: null, group: 'theme',
                label: 'Previously published theme',
                description: 'Set automatically when a theme is published, so one click restores what was live before. Not edited by hand.',
                rules: ['nullable', 'string', 'max:32'],
            ),

            // --- Chat (blueprint §15) ----------------------------------------
            new SettingDefinition(
                key: 'chat.max_message_length', type: 'int', default: 16000, group: 'chat',
                label: 'Longest message a customer may send',
                description: 'In characters. Very long messages cost more and are usually a paste accident.',
                rules: ['integer', 'min:100', 'max:200000'],
            ),
            new SettingDefinition(
                key: 'chat.context_message_limit', type: 'int', default: 20, group: 'chat',
                label: 'Messages of history to send',
                description: 'How much of the conversation the AI sees. Higher costs more on every message.',
                rules: ['integer', 'min:2', 'max:200'],
            ),
            new SettingDefinition(
                key: 'chat.context_token_budget', type: 'int', default: 8000, group: 'chat',
                label: 'Token budget for history',
                description: 'A hard ceiling on the history sent, whatever the message limit says. Protects against one enormous message.',
                rules: ['integer', 'min:500', 'max:500000'],
            ),
            new SettingDefinition(
                key: 'chat.max_output_tokens', type: 'int', default: 2048, group: 'chat',
                label: 'Longest reply',
                rules: ['integer', 'min:64', 'max:32000'],
            ),
            new SettingDefinition(
                key: 'chat.max_attachments', type: 'int', default: 4, group: 'chat',
                label: 'Attachments per message',
                rules: ['integer', 'min:0', 'max:20'],
            ),
            new SettingDefinition(
                key: 'chat.rate_limit_per_minute', type: 'int', default: 20, group: 'chat',
                label: 'Messages per minute, per customer',
                description: 'Protects your provider bill from a runaway script.',
                rules: ['integer', 'min:1', 'max:600'],
            ),
            new SettingDefinition(
                key: 'chat.streaming_enabled', type: 'bool', default: true, group: 'chat',
                label: 'Stream replies word by word',
                description: 'Switch off if your host buffers output — replies then arrive complete instead of live.',
            ),
            new SettingDefinition(
                key: 'chat.retention_days', type: 'int', default: 0, group: 'chat',
                label: 'Delete conversations after (days)',
                description: '0 keeps them forever. Anything else permanently deletes older conversations.',
                rules: ['integer', 'min:0', 'max:3650'],
            ),

            // --- Routing and health (blueprint §14, §24) ---------------------
            new SettingDefinition(
                key: 'routing.default_mode', type: 'string', default: 'auto', group: 'routing',
                label: 'How Aziv AI chooses a model',
                description: 'Applies to every conversation that has not chosen for itself.',
                rules: ['string', 'in:auto,best_quality,fastest,lowest_cost,free_only,admin_preferred,specific_provider,specific_model'],
            ),
            new SettingDefinition(
                key: 'routing.max_fallback_depth', type: 'int', default: 2, group: 'routing',
                label: 'How many other providers to try',
                description: 'After a provider fails, how many alternatives to attempt before giving up. 0 disables fallback.',
                rules: ['integer', 'min:0', 'max:5'],
            ),
            new SettingDefinition(
                key: 'routing.max_retries', type: 'int', default: 2, group: 'routing',
                label: 'Retries per provider',
                description: 'Only failures worth retrying are retried — a rejected key never is.',
                rules: ['integer', 'min:0', 'max:5'],
            ),
            new SettingDefinition(
                key: 'routing.retry_base_ms', type: 'int', default: 400, group: 'routing',
                label: 'First retry delay (ms)',
                description: 'Doubles each attempt, with a random jitter so many requests do not retry in lockstep.',
                rules: ['integer', 'min:50', 'max:10000'],
            ),
            new SettingDefinition(
                key: 'routing.circuit_failure_threshold', type: 'int', default: 5, group: 'routing',
                label: 'Failures before a provider is taken out',
                rules: ['integer', 'min:1', 'max:50'],
            ),
            new SettingDefinition(
                key: 'routing.circuit_cooldown_seconds', type: 'int', default: 60, group: 'routing',
                label: 'Rest period before retrying a failed provider (seconds)',
                description: 'Doubles each time it fails again, up to the maximum below.',
                rules: ['integer', 'min:5', 'max:3600'],
            ),
            new SettingDefinition(
                key: 'routing.circuit_max_cooldown_seconds', type: 'int', default: 900, group: 'routing',
                label: 'Longest rest period (seconds)',
                rules: ['integer', 'min:30', 'max:86400'],
            ),
            new SettingDefinition(
                key: 'routing.health_window_hours', type: 'int', default: 24, group: 'routing',
                label: 'Health is measured over the last (hours)',
                description: 'Latency and success rates come from real customer traffic in this window, not synthetic pings.',
                rules: ['integer', 'min:1', 'max:720'],
            ),

            // --- Costing (blueprint §13, §21) --------------------------------
            new SettingDefinition(
                key: 'billing.base_currency', type: 'string', default: 'INR', group: 'billing',
                label: 'Your reporting currency', isPublic: true,
                description: 'Provider costs are recorded in the currency the provider bills in, and converted to this for reporting.',
                rules: ['string', 'size:3'],
            ),
            new SettingDefinition(
                key: 'billing.exchange_rate_source', type: 'string', default: '', group: 'billing',
                label: 'Exchange rate feed (optional)',
                description: 'A URL returning {"rates": {"USD": 0.012, ...}}. Use {base} where your own currency code goes. Leave empty to enter rates by hand.',
                rules: ['nullable', 'string', 'max:255'],
            ),
            new SettingDefinition(
                key: 'billing.usage_retention_days', type: 'int', default: 730, group: 'billing',
                label: 'Keep detailed usage records for (days)',
                description: 'Daily totals are kept regardless, so long-range reporting survives.',
                rules: ['integer', 'min:30', 'max:3650'],
            ),

            // --- Notifications (blueprint §22) -------------------------------
            new SettingDefinition(
                key: 'notifications.email_enabled', type: 'bool', default: true, group: 'notifications',
                label: 'Send email notifications',
                description: 'Turn off to stop every outgoing email except password resets and email verification, which are part of signing in. In-app notices keep working.',
            ),

            // --- Manual renewal (Owner Addendum D §3) ------------------------
            new SettingDefinition(
                key: 'billing.renewal_notice_days', type: 'int', default: 7, group: 'billing',
                label: 'Send the renewal invoice this many days early',
                description: 'A subscription that renews by invoice needs the bill before the period ends, not on the day it stops.',
                rules: ['integer', 'min:1', 'max:60'],
            ),
            new SettingDefinition(
                key: 'billing.renewal_grace_days', type: 'int', default: 7, group: 'billing',
                label: 'Keep access this many days after an unpaid renewal',
                description: 'A customer who is late still has a working account, and a link to put it right. Set to 0 to stop access the moment the period ends.',
                rules: ['integer', 'min:0', 'max:60'],
            ),
            new SettingDefinition(
                key: 'billing.renewal_link_days', type: 'int', default: 30, group: 'billing',
                label: 'A payment link stays usable for (days)',
                description: 'The link in a renewal email expires after this. Long enough to be useful, short enough that an old email is not a way in.',
                rules: ['integer', 'min:1', 'max:180'],
            ),

            // --- Knowledge bases (blueprint §17, decision D-03) --------------
            new SettingDefinition(
                key: 'knowledge.enabled', type: 'bool', default: true, group: 'knowledge',
                label: 'Let customers use knowledge bases',
                description: 'Uploading documents and asking questions about them. Turning this off hides the feature; nothing already indexed is deleted.',
            ),
            new SettingDefinition(
                key: 'knowledge.vector_store', type: 'string', default: 'database', group: 'knowledge',
                label: 'Where document vectors are stored',
                description: 'Decision D-03: the database, which needs nothing extra installed. A dedicated vector service can be added later without re-indexing being a code change.',
                rules: ['string', 'max:32'],
            ),
            new SettingDefinition(
                key: 'knowledge.max_documents_per_base', type: 'int', default: 200, group: 'knowledge',
                label: 'Documents per knowledge base',
                description: 'A ceiling that protects search speed. The database vector store reads every chunk in a base on each question.',
                rules: ['integer', 'min:1', 'max:10000'],
            ),
            new SettingDefinition(
                key: 'knowledge.storage_mb_per_customer', type: 'int', default: 200, group: 'knowledge',
                label: 'Storage per customer (MB)',
                description: 'Total size of the documents one customer may keep. A plan can raise this with the knowledge.storage_mb feature.',
                rules: ['integer', 'min:1', 'max:1000000'],
            ),
            new SettingDefinition(
                key: 'knowledge.retention_days', type: 'int', default: 0, group: 'knowledge',
                label: 'Delete unused documents after (days)',
                description: 'Counted from the last time a document was searched. Zero keeps them until somebody deletes them.',
                rules: ['integer', 'min:0', 'max:3650'],
            ),
            new SettingDefinition(
                key: 'knowledge.max_context_tokens', type: 'int', default: 2000, group: 'knowledge',
                label: 'Most tokens of document text per answer',
                description: 'Retrieved passages compete with the conversation itself for the model\'s context. This caps how much of it they may take.',
                rules: ['integer', 'min:200', 'max:100000'],
            ),

            // --- Images (§16) ------------------------------------------------
            new SettingDefinition(
                key: 'images.enabled', type: 'bool', default: true, group: 'images',
                label: 'Let customers generate images',
                description: 'Turning this off hides the studio. Images already made are kept and stay viewable.',
            ),
            new SettingDefinition(
                key: 'images.max_per_day', type: 'int', default: 50, group: 'images',
                label: 'Images per customer per day',
                description: 'A ceiling that applies to everybody, on top of whatever their plan allows. It exists so one account cannot spend a month of provider budget in an afternoon. Zero removes it.',
                rules: ['integer', 'min:0', 'max:10000'],
            ),
            new SettingDefinition(
                key: 'images.default_size', type: 'string', default: '1024x1024', group: 'images',
                label: 'Size the studio opens on',
                description: 'Customers can change it. Larger sizes cost more at every provider.',
                rules: ['string', 'max:16'],
            ),
            new SettingDefinition(
                key: 'images.retention_days', type: 'int', default: 0, group: 'images',
                label: 'Delete generated images after (days)',
                description: 'Counted from when the image was made. Zero keeps them until somebody deletes them. The prompt is kept either way, so the customer can ask again.',
                rules: ['integer', 'min:0', 'max:3650'],
            ),

            // --- Voice (§18) -------------------------------------------------
            new SettingDefinition(
                key: 'voice.input_enabled', type: 'bool', default: true, group: 'voice',
                label: 'Let customers speak to Aziv AI',
                description: 'The microphone button in the composer. Needs a provider offering speech to text.',
            ),
            new SettingDefinition(
                key: 'voice.output_enabled', type: 'bool', default: true, group: 'voice',
                label: 'Let customers hear replies read aloud',
                description: 'The play button on a reply. Needs a provider offering text to speech.',
            ),
            new SettingDefinition(
                key: 'voice.speech_voice', type: 'string', default: '', group: 'voice',
                label: 'Which voice reads replies',
                description: 'Your provider\'s own name for one of its voices, copied from their documentation and passed through unchanged — every provider names them differently and there is no common list. Leave it empty for the provider\'s default.',
                rules: ['nullable', 'string', 'max:64'],
            ),
            new SettingDefinition(
                key: 'voice.max_recording_seconds', type: 'int', default: 120, group: 'voice',
                label: 'Longest recording (seconds)',
                description: 'Transcription is charged by the second at every provider, so this is the ceiling on what one message can cost.',
                rules: ['integer', 'min:5', 'max:1800'],
            ),
            new SettingDefinition(
                key: 'voice.max_speech_characters', type: 'int', default: 4000, group: 'voice',
                label: 'Most characters read aloud at once',
                description: 'A long reply is split or truncated rather than turning one click into a large bill.',
                rules: ['integer', 'min:200', 'max:50000'],
            ),
            new SettingDefinition(
                key: 'voice.max_minutes_per_day', type: 'int', default: 60, group: 'voice',
                label: 'Minutes of audio per customer per day',
                description: 'Recording and playback together, on top of whatever a plan allows. Zero removes the ceiling.',
                rules: ['integer', 'min:0', 'max:10000'],
            ),
            new SettingDefinition(
                key: 'voice.retention_days', type: 'int', default: 7, group: 'voice',
                label: 'Delete audio after (days)',
                description: 'Recordings and synthesised speech both. The TRANSCRIPT stays in the conversation — it is the message. Zero keeps the audio until somebody deletes it.',
                rules: ['integer', 'min:0', 'max:365'],
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
