<?php

namespace Tests\Feature\Settings;

use App\Domains\Settings\Models\Setting;
use App\Domains\Settings\Services\SettingsService;
use App\Domains\Settings\Support\SettingDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SettingsServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): SettingsService
    {
        return app(SettingsService::class);
    }

    public function test_a_setting_falls_back_to_its_declared_default(): void
    {
        $this->assertSame('Aziv AI', settings('branding.app_name'));
        $this->assertSame(12, settings('auth.password_min_length'));
        $this->assertTrue(settings('auth.registration_enabled'));
    }

    public function test_values_come_back_in_their_declared_type(): void
    {
        $this->service()->set('auth.password_min_length', 16);
        $this->service()->set('auth.registration_enabled', false);
        $this->service()->set('uploads.allowed_extensions', ['pdf', 'png']);

        $this->assertIsInt(settings('auth.password_min_length'));
        $this->assertIsBool(settings('auth.registration_enabled'));
        $this->assertIsArray(settings('uploads.allowed_extensions'));
        $this->assertFalse(settings('auth.registration_enabled'));
    }

    public function test_writing_a_setting_invalidates_the_cache(): void
    {
        $this->assertSame('Aziv AI', settings('branding.app_name'));

        $this->service()->set('branding.app_name', 'Renamed');

        // A stale cache here would mean the Admin Panel appears to do nothing.
        $this->assertSame('Renamed', app(SettingsService::class)->get('branding.app_name'));
    }

    /**
     * Several hundred settings must cost ONE cache read, not several hundred
     * queries — otherwise the settings system has to be torn out later.
     */
    public function test_reading_many_settings_hits_the_database_once(): void
    {
        $this->service()->set('branding.app_name', 'Cached');
        Cache::forget(SettingsService::CACHE_KEY);

        $service = app()->makeWith(SettingsService::class, []);
        app()->forgetInstance(SettingsService::class);
        $service = app(SettingsService::class);

        DB::enableQueryLog();
        foreach (['branding.app_name', 'branding.tagline', 'auth.password_min_length',
                  'ui.pagination_default', 'system.default_currency'] as $key) {
            $service->get($key);
        }
        $queries = collect(DB::getRawQueryLog())
            ->filter(fn ($q) => str_contains($q['raw_query'] ?? '', 'system_settings'))
            ->count();
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(1, $queries,
            'Reading five settings should touch the settings table at most once.');
    }

    public function test_an_undeclared_setting_is_rejected_rather_than_silently_stored(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service()->set('typo.that.nobody.declared', 'value');
    }

    public function test_a_value_failing_its_declared_rules_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        // Declared as min:8
        $this->service()->set('auth.password_min_length', 2);
    }

    public function test_set_returns_the_previous_value_so_the_change_can_be_audited(): void
    {
        $previous = $this->service()->set('branding.app_name', 'New Name');

        $this->assertSame('Aziv AI', $previous);
    }

    /** Secrets are encrypted at rest and never appear in the public payload. */
    public function test_a_secret_setting_is_encrypted_and_excluded_from_the_public_payload(): void
    {
        $this->service()->registry()->register(new SettingDefinition(
            key: 'test.secret_value', type: 'string', group: 'test',
            isSecret: true, isPublic: true,   // isPublic must not win over isSecret
        ));

        $this->service()->set('test.secret_value', 'super-secret-token');

        $stored = Setting::where('key', 'test.secret_value')->value('value');
        $this->assertNotSame('super-secret-token', $stored, 'A secret was stored in plain text.');
        $this->assertStringNotContainsString('super-secret-token', $stored ?? '');

        // Round-trips correctly for the application...
        $this->assertSame('super-secret-token', settings('test.secret_value'));
        // ...but never reaches the browser.
        $this->assertArrayNotHasKey('test.secret_value', $this->service()->publicPayload());
    }

    public function test_the_public_payload_contains_only_settings_marked_public(): void
    {
        $payload = $this->service()->publicPayload();

        $this->assertArrayHasKey('branding.app_name', $payload);
        $this->assertArrayNotHasKey('auth.password_min_length', $payload);
        $this->assertArrayNotHasKey('uploads.scanner', $payload);
    }

    public function test_settings_can_be_read_by_group(): void
    {
        $branding = $this->service()->group('branding');

        $this->assertArrayHasKey('branding.app_name', $branding);
        $this->assertArrayNotHasKey('auth.password_min_length', $branding);
    }
}
