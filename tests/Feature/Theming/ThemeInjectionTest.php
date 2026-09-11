<?php

namespace Tests\Feature\Theming;

use App\Domains\Security\Services\PermissionRegistry;
use App\Domains\Theming\Models\Theme;
use App\Domains\Theming\Services\ThemeService;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\ThemesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The Phase 2 brief: the Admin Panel must use the same token-based theme
 * system, and branding must propagate "without template-specific overrides".
 * These tests hold both panels to that.
 */
class ThemeInjectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(ThemesSeeder::class);
        app(ThemeService::class)->flush();
    }

    public function test_a_customer_page_carries_the_compiled_token_block(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('id="aziv-theme"', false)
            ->assertSee('--color-primary:', false);
    }

    public function test_the_admin_panel_carries_the_same_block_under_the_admin_scope(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(PermissionRegistry::SUPER_ADMIN);

        $this->actingAs($admin->fresh())
            ->get('/admin')
            ->assertOk()
            ->assertSee('id="aziv-theme"', false)
            ->assertSee('--color-primary:', false);
    }

    /**
     * Changing ONE palette colour must reach both panels. If it reaches only
     * one, something is overriding the token per template, which is the exact
     * thing owner decision D-07 forbids.
     */
    public function test_one_branding_change_reaches_both_panels(): void
    {
        $theme = Theme::where('is_active', true)->first();

        $theme->tokens()
            ->where('token_key', 'color.primary')
            ->update(['token_value' => '#ff6600']);

        $theme->increment('version');
        app(ThemeService::class)->flush();

        $admin = User::factory()->create();
        $admin->assignRole(PermissionRegistry::SUPER_ADMIN);

        $this->get('/login')->assertSee('--color-primary:#ff6600', false);
        $this->actingAs($admin->fresh())->get('/admin')->assertSee('--color-primary:#ff6600', false);
    }

    /**
     * REGRESSION. rememberForever() on an Eloquent model serialises the whole
     * object. On a serialising driver — file, database, redis — that payload
     * outlives the code that wrote it, and the next deploy that touches the
     * model returns __PHP_Incomplete_Class on every page, login included.
     *
     * The test suite runs the ARRAY driver, which stores the object by
     * reference and never serialises, so the fault is invisible to an ordinary
     * assertion. Asserting the cached value is a scalar catches it on any
     * driver.
     */
    public function test_the_active_theme_is_cached_as_an_id_not_as_a_model(): void
    {
        app(ThemeService::class)->active();

        $cached = Cache::get('aziv:theme:active');

        $this->assertIsInt($cached);
        $this->assertSame(Theme::where('is_active', true)->value('id'), $cached);

        // And the same value must survive a round trip through a serialising
        // store, which an Eloquent instance is not guaranteed to do.
        $this->assertSame($cached, unserialize(serialize($cached)));
    }

    public function test_the_panel_still_boots_when_the_theme_cannot_be_read(): void
    {
        // Simulates a database that is down or not yet migrated: the Admin
        // Panel has to come up, because System Health is how an administrator
        // finds out WHY it is down.
        DB::statement('DROP TABLE theme_tokens');
        DB::statement('DROP TABLE themes');
        Cache::flush();

        $admin = User::factory()->create();
        $admin->assignRole(PermissionRegistry::SUPER_ADMIN);

        $this->actingAs($admin->fresh())->get('/admin/system-health')->assertOk();
    }
}
