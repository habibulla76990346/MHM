<?php

namespace Tests\Feature\Branding;

use App\Domains\Branding\Support\BrandAsset;
use App\Domains\Security\Models\ActivityLog;
use App\Domains\Security\Services\PermissionRegistry;
use App\Filament\Pages\Branding;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

class BrandingPageTest extends TestCase
{
    use RefreshDatabase;

    private array $published = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->published as $path) {
            @unlink(public_path($path));
        }

        parent::tearDown();
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user->fresh();
    }

    public function test_the_page_is_permission_gated(): void
    {
        $customer = $this->userWithRole(PermissionRegistry::CUSTOMER);
        $this->actingAs($customer)->get('/admin/branding')->assertForbidden();

        $this->actingAs($this->userWithRole(PermissionRegistry::SUPER_ADMIN))
            ->get('/admin/branding')
            ->assertOk()
            ->assertSee('Branding');
    }

    public function test_renaming_the_product_reaches_the_customer_site(): void
    {
        Livewire::actingAs($this->userWithRole(PermissionRegistry::SUPER_ADMIN))
            ->test(Branding::class)
            ->set('text.branding__app_name', 'Contoso AI')
            ->call('saveText');

        $this->assertSame('Contoso AI', settings('branding.app_name'));

        // The point of the setting: it must actually appear on the site,
        // rather than the product name being frozen in .env.
        $this->get('/')->assertOk()->assertSee('Contoso AI');
    }

    public function test_a_text_change_is_audited_with_before_and_after(): void
    {
        $before = settings('branding.tagline');

        Livewire::actingAs($this->userWithRole(PermissionRegistry::SUPER_ADMIN))
            ->test(Branding::class)
            ->set('text.branding__tagline', 'A new tagline')
            ->call('saveText');

        $entry = ActivityLog::where('action', 'branding.text_updated')->latest('id')->first();

        $this->assertNotNull($entry);
        $this->assertSame($before, $entry->before['branding.tagline']);
        $this->assertSame('A new tagline', $entry->after['branding.tagline']);
    }

    public function test_an_invalid_value_is_reported_rather_than_stored(): void
    {
        Livewire::actingAs($this->userWithRole(PermissionRegistry::SUPER_ADMIN))
            ->test(Branding::class)
            ->set('text.branding__support_email', 'not-an-email')
            ->call('saveText');

        $this->assertNull(settings('branding.support_email'));
    }

    public function test_uploading_replaces_the_asset_and_is_audited(): void
    {
        $page = Livewire::actingAs($this->userWithRole(PermissionRegistry::SUPER_ADMIN))
            ->test(Branding::class)
            ->set('uploads.favicon', UploadedFile::fake()->image('icon.png', 64, 64))
            ->call('upload', 'favicon');

        $path = settings(BrandAsset::settingKey('favicon'));
        $this->published[] = $path;

        $this->assertNotNull($path);
        $this->assertFileExists(public_path($path));
        $this->assertDatabaseHas('activity_logs', ['action' => 'branding.asset_replaced']);

        // The pending upload is cleared, so a second click cannot re-publish it.
        $page->assertSet('uploads.favicon', null);
    }

    public function test_a_rejected_upload_changes_nothing(): void
    {
        Livewire::actingAs($this->userWithRole(PermissionRegistry::SUPER_ADMIN))
            ->test(Branding::class)
            ->set('uploads.favicon', UploadedFile::fake()->create('payload.svg', 4, 'image/svg+xml'))
            ->call('upload', 'favicon');

        $this->assertNull(settings(BrandAsset::settingKey('favicon')));
        $this->assertDatabaseMissing('activity_logs', ['action' => 'branding.asset_replaced']);
    }

    /**
     * Replacing the logo writes a file into the web root and changes what
     * every customer sees, so it is deliberately NOT bundled with the general
     * support permissions.
     */
    public function test_support_cannot_reach_branding_at_all(): void
    {
        $support = $this->userWithRole(PermissionRegistry::SUPPORT_MANAGER);

        $this->assertFalse($support->can('branding.view'));
        $this->assertFalse($support->can('branding.manage'));

        $this->actingAs($support)->get('/admin/branding')->assertForbidden();
    }

    /** A Content Manager runs the site's appearance, so they do hold it. */
    public function test_a_content_manager_can_change_branding(): void
    {
        $manager = $this->userWithRole(PermissionRegistry::CONTENT_MANAGER);

        $this->assertTrue($manager->can('branding.manage'));

        Livewire::actingAs($manager)
            ->test(Branding::class)
            ->set('text.branding__tagline', 'Set by the content manager')
            ->call('saveText');

        $this->assertSame('Set by the content manager', settings('branding.tagline'));
    }

    /**
     * The guest-facing pages must show the administrator's product name too —
     * a visitor with no account sees branding before anything else.
     */
    public function test_the_product_name_reaches_a_signed_out_visitor(): void
    {
        settings()->set('branding.app_name', 'Contoso AI');

        $this->get('/login')->assertOk()->assertSee('Contoso AI');
        $this->get('/')->assertOk()->assertSee('Contoso AI');
    }
}
