<?php

namespace Tests\Feature\Content;

use App\Domains\Content\Models\NavigationItem;
use App\Domains\Content\Models\NavigationMenu;
use App\Domains\Content\Models\Page;
use App\Domains\Content\Services\FeatureFlagService;
use App\Domains\Security\Models\ActivityLog;
use App\Domains\Security\Services\PermissionRegistry;
use App\Filament\Resources\FeatureFlags\FeatureFlagResource;
use App\Filament\Resources\FeatureFlags\Pages\ListFeatureFlags;
use App\Filament\Resources\NavigationItems\Pages\CreateNavigationItem;
use App\Filament\Resources\Pages\PageResource;
use App\Models\User;
use Database\Seeders\ContentSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\ThemesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ContentAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(ThemesSeeder::class);
        $this->seed(ContentSeeder::class);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user->fresh();
    }

    public function test_the_content_screens_open_for_a_content_manager(): void
    {
        $manager = $this->userWithRole(PermissionRegistry::CONTENT_MANAGER);

        foreach (['/admin/pages', '/admin/banners', '/admin/faqs', '/admin/navigation-items'] as $url) {
            $this->actingAs($manager)->get($url)->assertOk();
        }
    }

    public function test_a_customer_cannot_reach_any_content_screen(): void
    {
        $customer = $this->userWithRole(PermissionRegistry::CUSTOMER);

        foreach (['/admin/pages', '/admin/banners', '/admin/faqs', '/admin/navigation-items', '/admin/feature-flags'] as $url) {
            $this->actingAs($customer)->get($url)->assertForbidden();
        }
    }

    /** Feature flags are system configuration, not content. */
    public function test_feature_flags_need_settings_permission_not_content_permission(): void
    {
        $manager = $this->userWithRole(PermissionRegistry::CONTENT_MANAGER);

        $this->assertTrue($manager->can('content.manage'));
        $this->assertFalse($manager->can('settings.manage'));
        $this->assertFalse(FeatureFlagResource::canCreate());

        $this->actingAs($this->userWithRole(PermissionRegistry::SUPER_ADMIN))
            ->get('/admin/feature-flags')
            ->assertOk();
    }

    /** A system page is referenced by the footer and navigation; never deletable. */
    public function test_a_system_page_cannot_be_deleted(): void
    {
        $this->actingAs($this->userWithRole(PermissionRegistry::SUPER_ADMIN));

        $home = Page::where('slug', 'home')->first();
        $this->assertTrue($home->is_system);
        $this->assertFalse(PageResource::canDelete($home));

        $ordinary = Page::create(['slug' => 'ordinary', 'title' => 'Ordinary', 'status' => Page::STATUS_DRAFT]);
        $this->assertTrue(PageResource::canDelete($ordinary));
    }

    /**
     * The bottom bar's cap exists because a fifth tab at 320px puts every
     * touch target under 44px, which fails the responsive gate. The editor
     * refuses it and explains why, rather than letting someone break the build.
     */
    public function test_the_editor_refuses_a_fifth_bottom_bar_item(): void
    {
        $menu = NavigationMenu::where('key', 'customer_bottom_nav')->first();
        $this->assertSame(4, $menu->items()->count());

        Livewire::actingAs($this->userWithRole(PermissionRegistry::SUPER_ADMIN))
            ->test(CreateNavigationItem::class)
            ->fillForm([
                'menu_id' => $menu->getKey(),
                'key' => 'fifth',
                'label' => 'Fifth',
                'destination_type' => 'route',
                'route_name' => 'account',
            ])
            ->call('create')
            ->assertHasFormErrors(['menu_id']);

        $this->assertSame(4, $menu->fresh()->items()->count());
        $this->assertDatabaseMissing('navigation_items', ['key' => 'fifth']);
    }

    public function test_the_editor_refuses_an_unsafe_external_link(): void
    {
        $menu = NavigationMenu::where('key', 'customer_drawer')->first();

        Livewire::actingAs($this->userWithRole(PermissionRegistry::SUPER_ADMIN))
            ->test(CreateNavigationItem::class)
            ->fillForm([
                'menu_id' => $menu->getKey(),
                'key' => 'nasty',
                'label' => 'Nasty',
                'destination_type' => 'external',
                'url' => 'javascript:alert(1)',
            ])
            ->call('create')
            ->assertHasFormErrors(['url']);

        $this->assertDatabaseMissing('navigation_items', ['key' => 'nasty']);
    }

    public function test_an_editor_can_add_an_item_to_a_menu_with_room(): void
    {
        $menu = NavigationMenu::where('key', 'customer_drawer')->first();

        Livewire::actingAs($this->userWithRole(PermissionRegistry::SUPER_ADMIN))
            ->test(CreateNavigationItem::class)
            ->fillForm([
                'menu_id' => $menu->getKey(),
                'key' => 'pricing',
                'label' => 'Pricing',
                'destination_type' => 'external',
                'url' => '/pricing',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('navigation_items', ['key' => 'pricing', 'label' => 'Pricing']);
    }

    public function test_toggling_a_feature_flag_is_audited(): void
    {
        $this->actingAs($this->userWithRole(PermissionRegistry::SUPER_ADMIN));

        $flag = app(FeatureFlagService::class)->set('beta.thing', false);

        Livewire::test(ListFeatureFlags::class)
            ->call('updateTableColumnState', 'is_enabled', (string) $flag->getKey(), true);

        // Rule 8 applies to a toggle exactly as to a form.
        $entry = ActivityLog::where('action', 'feature_flag.toggled')->latest('id')->first();

        $this->assertNotNull($entry);
        $this->assertFalse($entry->before['is_enabled']);
        $this->assertTrue($entry->after['is_enabled']);
        $this->assertTrue($flag->fresh()->is_enabled);
    }

    public function test_an_item_whose_destination_is_broken_is_flagged_in_the_list(): void
    {
        NavigationItem::whereHas('menu', fn ($q) => $q->where('key', 'customer_primary'))
            ->where('key', 'chat')
            ->update(['route_name' => 'gone.missing']);

        // The list shows whether a link actually resolves, not merely what it
        // was configured to point at.
        $this->actingAs($this->userWithRole(PermissionRegistry::SUPER_ADMIN))
            ->get('/admin/navigation-items')
            ->assertOk()
            ->assertSee('Not reachable');
    }
}
