<?php

namespace Tests\Feature\Theming;

use App\Domains\Theming\Models\Theme;
use App\Domains\Theming\Models\ThemeToken;
use App\Domains\Theming\Services\ThemeService;
use App\Domains\Theming\Support\TokenCatalogue;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\ThemesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ThemeEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(ThemesSeeder::class);
        app(ThemeService::class)->flush();
    }

    private function themes(): ThemeService
    {
        return app(ThemeService::class);
    }

    public function test_the_built_in_themes_seed_with_a_full_token_set(): void
    {
        $this->assertSame(8, Theme::count());

        $expected = count(TokenCatalogue::tokens()) * 2 * 2;   // scopes x modes

        foreach (Theme::all() as $theme) {
            $this->assertSame($expected, $theme->tokens()->count(), "{$theme->slug} is missing tokens.");
        }
    }

    public function test_exactly_one_theme_is_active_and_it_cannot_be_deleted(): void
    {
        $this->assertSame(1, Theme::where('is_active', true)->count());
        $this->assertFalse(Theme::where('is_active', true)->first()->isDeletable());

        // Built-ins are never deletable, so there is always a way back.
        $this->assertFalse(Theme::where('slug', 'ocean')->first()->isDeletable());
    }

    /**
     * Re-running the seeder is part of upgrading. It must add tokens that are
     * new in a release without reverting anything an administrator has
     * changed.
     */
    public function test_reseeding_does_not_overwrite_an_administrators_edit(): void
    {
        $token = ThemeToken::where('token_key', 'color.primary')
            ->where('scope', TokenCatalogue::SCOPE_CUSTOMER)
            ->where('mode', TokenCatalogue::MODE_LIGHT)
            ->first();

        $token->update(['token_value' => '#ff00aa']);
        $count = ThemeToken::count();

        $this->seed(ThemesSeeder::class);

        $this->assertSame('#ff00aa', $token->fresh()->token_value);
        $this->assertSame($count, ThemeToken::count());
        $this->assertSame(8, Theme::count());
    }

    public function test_compiled_css_carries_all_three_dark_selectors(): void
    {
        $css = $this->themes()->compiledCss(TokenCatalogue::SCOPE_CUSTOMER);

        $this->assertStringStartsWith(':root{', $css);
        $this->assertStringContainsString('--color-primary:', $css);
        // The customer app stamps data-theme; Filament toggles a class; a
        // viewer who chose neither is matched by the media query. All three.
        $this->assertStringContainsString('@media (prefers-color-scheme:dark)', $css);
        $this->assertStringContainsString(":root[data-theme='dark']", $css);
        $this->assertStringContainsString(':root.dark{', $css);
    }

    /**
     * Owner decision D-07 and the Phase 2 brief: one engine, two scopes, and
     * branding that reaches the Admin Panel with no template-specific override.
     */
    public function test_the_admin_scope_compiles_the_same_colours_as_the_customer_scope(): void
    {
        $theme = Theme::where('is_active', true)->first();

        $customer = $theme->tokenMap(TokenCatalogue::SCOPE_CUSTOMER, TokenCatalogue::MODE_LIGHT);
        $admin = $theme->tokenMap(TokenCatalogue::SCOPE_ADMIN, TokenCatalogue::MODE_LIGHT);

        foreach (TokenCatalogue::colorTokens() as $key) {
            $this->assertSame($customer[$key], $admin[$key], "Admin diverged on {$key}.");
        }
    }

    /**
     * The compiled block is emitted unescaped, because it IS a stylesheet.
     * A token value must therefore not be able to close the element.
     */
    public function test_a_token_value_cannot_break_out_of_the_style_element(): void
    {
        $theme = Theme::where('is_active', true)->first();

        $theme->tokens()
            ->where('token_key', 'color.primary')
            ->where('scope', TokenCatalogue::SCOPE_CUSTOMER)
            ->where('mode', TokenCatalogue::MODE_LIGHT)
            ->update(['token_value' => 'red}</style><script>alert(1)</script><style>{a:b']);

        $theme->increment('version');
        $this->themes()->flush();

        $css = $this->themes()->compiledCss(TokenCatalogue::SCOPE_CUSTOMER);

        foreach (['<', '>', '</style', '<script', '}', ';alert'] as $needle) {
            $this->assertStringNotContainsString($needle, substr($css, 6, 120), "Escaped through with: {$needle}");
        }
    }

    public function test_publishing_records_the_previous_theme_so_it_can_be_restored(): void
    {
        $ocean = Theme::where('slug', 'ocean')->first();
        $before = Theme::where('is_active', true)->first();

        $this->themes()->publish($ocean);

        $this->assertTrue($ocean->fresh()->is_active);
        $this->assertSame(1, Theme::where('is_active', true)->count());
        $this->assertNotNull($ocean->fresh()->published_at);

        $restored = $this->themes()->restorePrevious();

        $this->assertNotNull($restored);
        $this->assertSame($before->slug, $restored->slug);
        $this->assertTrue($before->fresh()->is_active);
        $this->assertFalse($ocean->fresh()->is_active);
    }

    public function test_publishing_bumps_the_version_so_compiled_css_cannot_go_stale(): void
    {
        $theme = Theme::where('is_active', true)->first();
        $version = $theme->version;

        $this->themes()->publish($theme);

        $this->assertSame($version + 1, $theme->fresh()->version);
    }

    /**
     * A half-finished theme must never reach a paying customer, so preview is
     * scoped to the previewing administrator's session.
     */
    public function test_preview_is_session_scoped_and_requires_permission(): void
    {
        $ocean = Theme::where('slug', 'ocean')->first();
        $published = Theme::where('is_active', true)->first();

        // No user at all: preview is ignored.
        session([ThemeService::PREVIEW_SESSION_KEY => $ocean->getKey()]);
        $this->assertSame($published->slug, app(ThemeService::class)->active()->slug);

        // A signed-in customer: still ignored.
        $customer = User::factory()->create();
        $this->actingAs($customer);
        $this->assertSame($published->slug, app(ThemeService::class)->active()->slug);
    }

    public function test_an_administrator_previewing_sees_the_draft(): void
    {
        $ocean = Theme::where('slug', 'ocean')->first();

        $admin = User::factory()->create();
        $admin->givePermissionTo('themes.manage');

        $this->actingAs($admin);
        session([ThemeService::PREVIEW_SESSION_KEY => $ocean->getKey()]);

        $service = app(ThemeService::class);

        $this->assertTrue($service->isPreviewing());
        $this->assertSame($ocean->slug, $service->active()->slug);
    }

    public function test_filament_receives_a_full_ramp_per_semantic_colour(): void
    {
        $colors = $this->themes()->filamentColors();

        foreach (['primary', 'danger', 'success', 'warning', 'info', 'gray'] as $name) {
            $this->assertArrayHasKey($name, $colors);
            $this->assertCount(11, $colors[$name]);
            $this->assertMatchesRegularExpression('/^oklch\(/', $colors[$name][600]);
        }
    }

    public function test_the_browser_chrome_colour_comes_from_the_theme(): void
    {
        $theme = Theme::where('is_active', true)->first();

        $this->assertSame(
            $theme->tokenMap(TokenCatalogue::SCOPE_CUSTOMER, TokenCatalogue::MODE_LIGHT)['color.background'],
            $this->themes()->themeColor('light'),
        );
        $this->assertNotSame($this->themes()->themeColor('light'), $this->themes()->themeColor('dark'));
    }

    /** With no theme at all the app falls back to the compiled-in defaults. */
    public function test_an_empty_installation_renders_without_a_theme(): void
    {
        ThemeToken::query()->delete();
        Theme::query()->delete();
        $this->themes()->flush();

        $this->assertSame('', app(ThemeService::class)->compiledCss());
        $this->assertSame([], app(ThemeService::class)->filamentColors());
        $this->assertSame('#f8fafc', app(ThemeService::class)->themeColor('light'));
    }

    /**
     * REGRESSION. Every write bumps the version, and the version is part of
     * the compiled-CSS cache key — so a write that fails to bump it serves the
     * old stylesheet forever.
     *
     * The failure mode was subtle: a theme created without an explicit version
     * has NULL in memory while the database holds 1, so `version + 1` wrote 1
     * over 1 and nothing moved. Duplicating first is what exposes it, because
     * that is the only way to get an editable theme.
     */
    public function test_every_write_moves_the_version_on_a_freshly_duplicated_theme(): void
    {
        $themes = $this->themes();
        $copy = $themes->duplicate(Theme::where('slug', 'ocean')->first(), 'Copy');

        $seen = [$copy->version];

        $themes->saveTokens($copy, TokenCatalogue::SCOPE_CUSTOMER, TokenCatalogue::MODE_LIGHT, ['color.primary' => '#123456']);
        $seen[] = $copy->fresh()->version;

        $themes->applyPalette($copy->fresh(), ['light' => ['primary' => '#654321'], 'dark' => ['primary' => '#abcdef']]);
        $seen[] = $copy->fresh()->version;

        $themes->saveCustomCss($copy->fresh(), '.hero{padding:1rem}');
        $seen[] = $copy->fresh()->version;

        $this->assertSame([1, 2, 3, 4], $seen, 'A write did not move the version, so its CSS would be served stale.');

        // And the compiled output actually changes with it.
        $themes->publish($copy->fresh());
        $themes->flush();

        $this->assertStringContainsString('--color-primary:#654321', app(ThemeService::class)->compiledCss());
    }

    public function test_saving_an_unsafe_token_value_is_rejected_before_it_is_stored(): void
    {
        $copy = $this->themes()->duplicate(Theme::where('slug', 'ocean')->first(), 'Copy');
        $before = $copy->tokenMap(TokenCatalogue::SCOPE_CUSTOMER, TokenCatalogue::MODE_LIGHT);

        $this->expectException(ValidationException::class);

        try {
            $this->themes()->saveTokens($copy, TokenCatalogue::SCOPE_CUSTOMER, TokenCatalogue::MODE_LIGHT, [
                'shadow.md' => '0 2px 4px url(https://evil.test/a.png)',
            ]);
        } finally {
            // Nothing partial was written.
            $this->assertSame($before, $copy->fresh()->tokenMap(TokenCatalogue::SCOPE_CUSTOMER, TokenCatalogue::MODE_LIGHT));
            $this->assertSame(1, $copy->fresh()->version);
        }
    }
}
