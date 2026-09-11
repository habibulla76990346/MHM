<?php

namespace Tests\Feature\Theming;

use App\Domains\Security\Models\ActivityLog;
use App\Domains\Security\Services\PermissionRegistry;
use App\Domains\Theming\Models\Theme;
use App\Domains\Theming\Services\ThemeService;
use App\Filament\Pages\Appearance;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\ThemesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AppearancePageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(ThemesSeeder::class);
        app(ThemeService::class)->flush();
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(PermissionRegistry::SUPER_ADMIN);

        return $user->fresh();
    }

    private function editableTheme(): Theme
    {
        // Built-ins are never editable in place, so every write test works on
        // a copy — which is the flow the page itself pushes an admin into.
        return app(ThemeService::class)->duplicate(Theme::where('slug', 'ocean')->first(), 'My Theme');
    }

    public function test_the_page_is_permission_gated(): void
    {
        $customer = User::factory()->create();
        $customer->assignRole(PermissionRegistry::CUSTOMER);

        $this->actingAs($customer->fresh())->get('/admin/appearance')->assertForbidden();
        $this->actingAs($this->admin())->get('/admin/appearance')->assertOk();
    }

    public function test_it_opens_on_the_live_theme(): void
    {
        Livewire::actingAs($this->admin())
            ->test(Appearance::class)
            ->assertSet('themeId', Theme::where('is_active', true)->value('id'))
            ->assertSet('scope', 'customer')
            ->assertSet('mode', 'light');
    }

    /** A built-in must not be editable in place, or there is no way back. */
    public function test_a_built_in_theme_cannot_be_edited(): void
    {
        $builtIn = Theme::where('slug', 'ocean')->first();
        $before = $builtIn->tokenMap('customer', 'light')['color.primary'];

        Livewire::actingAs($this->admin())
            ->test(Appearance::class)
            ->set('themeId', $builtIn->getKey())
            ->set('values.color__primary', '#ff0000')
            ->call('save');

        $this->assertSame($before, $builtIn->fresh()->tokenMap('customer', 'light')['color.primary']);
    }

    public function test_saving_a_token_persists_bumps_the_version_and_writes_an_audit_entry(): void
    {
        $theme = $this->editableTheme();
        $version = $theme->version;

        Livewire::actingAs($this->admin())
            ->test(Appearance::class)
            ->set('themeId', $theme->getKey())
            ->set('values.color__primary', '#ff6600')
            ->call('save');

        $this->assertSame('#ff6600', $theme->fresh()->tokenMap('customer', 'light')['color.primary']);
        $this->assertSame($version + 1, $theme->fresh()->version);

        // Rule 8: authorise, validate, audit with before and after.
        $entry = ActivityLog::where('action', 'theme.tokens_updated')->latest('id')->first();
        $this->assertNotNull($entry);
        $this->assertSame('#ff6600', $entry->after['color.primary']);
        $this->assertArrayHasKey('color.primary', $entry->before);
    }

    public function test_an_invalid_value_is_refused_rather_than_silently_mangled(): void
    {
        $theme = $this->editableTheme();
        $before = $theme->tokenMap('customer', 'light')['color.primary'];

        Livewire::actingAs($this->admin())
            ->test(Appearance::class)
            ->set('themeId', $theme->getKey())
            ->set('values.color__primary', 'red}</style><script>alert(1)</script>')
            ->call('save');

        $this->assertSame($before, $theme->fresh()->tokenMap('customer', 'light')['color.primary']);
        $this->assertDatabaseMissing('activity_logs', ['action' => 'theme.tokens_updated']);
    }

    /**
     * The D-07 guarantee, exercised through the UI an owner actually uses:
     * seven colours in, both panels and both modes rebuilt.
     */
    public function test_applying_a_palette_rebuilds_both_scopes_and_both_modes(): void
    {
        $theme = $this->editableTheme();

        Livewire::actingAs($this->admin())
            ->test(Appearance::class)
            ->set('themeId', $theme->getKey())
            ->set('palette.light.primary', '#0b5fff')
            ->set('palette.dark.primary', '#7aa7ff')
            ->call('applyPalette');

        $theme = $theme->fresh();

        $this->assertSame('#0b5fff', $theme->tokenMap('customer', 'light')['color.primary']);
        $this->assertSame('#0b5fff', $theme->tokenMap('admin', 'light')['color.primary']);
        $this->assertSame('#7aa7ff', $theme->tokenMap('customer', 'dark')['color.primary']);
        $this->assertSame('#7aa7ff', $theme->tokenMap('admin', 'dark')['color.primary']);

        // Derived values moved with it, rather than being left behind.
        $this->assertNotSame(
            $theme->tokenMap('customer', 'light')['color.primary-hover'],
            $theme->tokenMap('customer', 'dark')['color.primary-hover'],
        );
    }

    public function test_search_filters_tokens_by_what_they_affect(): void
    {
        $page = Livewire::actingAs($this->admin())->test(Appearance::class)->set('search', 'modal');

        $groups = $page->instance()->groups();
        $keys = collect($groups)->flatMap(fn ($g) => array_keys($g['tokens']))->all();

        $this->assertContains('color.modal-bg', $keys);
        $this->assertNotContains('color.success', $keys);
    }

    public function test_custom_css_is_sanitised_before_it_is_stored(): void
    {
        $theme = $this->editableTheme();

        Livewire::actingAs($this->admin())
            ->test(Appearance::class)
            ->set('themeId', $theme->getKey())
            ->set('customCss', "@import url('https://evil.test/x.css'); .hero{padding:2rem}")
            ->call('saveCustomCss');

        $stored = $theme->fresh()->custom_css;

        // Stored sanitised, not sanitised at render: the dangerous text never
        // sits in the database waiting for a careless {!! !!}.
        $this->assertStringNotContainsString('evil.test', $stored);
        $this->assertStringContainsString('padding:2rem', $stored);
    }

    public function test_custom_css_requires_its_own_permission(): void
    {
        $theme = $this->editableTheme();

        // Content Manager holds themes.manage but not themes.custom_css.
        $manager = User::factory()->create();
        $manager->assignRole(PermissionRegistry::CONTENT_MANAGER);

        Livewire::actingAs($manager->fresh())
            ->test(Appearance::class)
            ->set('themeId', $theme->getKey())
            ->set('customCss', '.hero{padding:2rem}')
            ->call('saveCustomCss');

        $this->assertNull($theme->fresh()->custom_css);
    }

    public function test_previewing_and_publishing_go_through_the_service(): void
    {
        $theme = $this->editableTheme();
        $live = Theme::where('is_active', true)->first();

        Livewire::actingAs($this->admin())
            ->test(Appearance::class)
            ->set('themeId', $theme->getKey())
            ->callAction('preview')
            ->assertSet('themeId', $theme->getKey());

        $this->assertTrue(app(ThemeService::class)->isPreviewing());

        Livewire::actingAs($this->admin())
            ->test(Appearance::class)
            ->set('themeId', $theme->getKey())
            ->callAction('publish');

        $this->assertTrue($theme->fresh()->is_active);
        $this->assertFalse($live->fresh()->is_active);
        $this->assertDatabaseHas('activity_logs', ['action' => 'theme.published']);
    }
}
