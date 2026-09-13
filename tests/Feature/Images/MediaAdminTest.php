<?php

namespace Tests\Feature\Images;

use App\Domains\AI\Models\AiModel;
use App\Domains\AI\Support\Capability;
use App\Domains\Images\Models\ImageGeneration;
use App\Domains\Images\Services\ImageService;
use App\Domains\Security\Services\PermissionRegistry;
use App\Filament\Pages\ImagesAndVoice;
use App\Filament\Resources\ImageGenerations\ImageGenerationResource;
use App\Filament\Resources\VoiceJobs\VoiceJobResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\GeneratesMedia;
use Tests\TestCase;

/**
 * What an administrator may see and change (§16, §18).
 *
 * THE LINE THIS SUITE DEFENDS: running the feature is not the same authority
 * as looking at what customers made with it. `media.view` shows how many
 * pictures were generated, what they cost and which failed — the operational
 * facts — and it does not open anybody's picture, exactly as `knowledge.view`
 * does not open a personal collection.
 */
class MediaAdminTest extends TestCase
{
    use GeneratesMedia, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpMediaFixtures();
        $this->installMediaStub();
        $this->mediaModel(Capability::IMAGE_GENERATION, 'painter-1');
    }

    private function admin(string $role = PermissionRegistry::ADMIN): User
    {
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->assignRole($role);

        return $admin->fresh();
    }

    public function test_an_administrator_sees_the_operational_facts(): void
    {
        app(ImageService::class)->request($this->user, 'a heron at dusk');

        $this->actingAs($this->admin())
            ->get(ImageGenerationResource::getUrl('index'))
            ->assertOk()
            ->assertSee('a heron at dusk');
    }

    public function test_an_administrator_cannot_open_a_customers_picture(): void
    {
        app(ImageService::class)->request($this->user, 'a private moment');
        $generation = ImageGeneration::first()->fresh('file');

        // media.view is the authority to RUN the feature. Looking at what
        // somebody made with it is a different thing and is not granted by it.
        $this->actingAs($this->admin())
            ->get(route('media.show', $generation->file))
            ->assertForbidden();
    }

    public function test_a_customer_never_reaches_the_admin_screens(): void
    {
        $this->user->forceFill(['email_verified_at' => now()])->save();

        $this->actingAs($this->user->fresh())
            ->get(ImageGenerationResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_a_finance_manager_has_no_media_authority_at_all(): void
    {
        // Deny by default: a permission added in a later phase reaches no role
        // until it is granted there explicitly.
        $finance = $this->admin(PermissionRegistry::FINANCE_MANAGER);

        $this->assertFalse($finance->can('media.view'));
        $this->actingAs($finance)->get(VoiceJobResource::getUrl('index'))->assertForbidden();
    }

    public function test_a_support_manager_may_look_but_never_delete(): void
    {
        $support = $this->admin(PermissionRegistry::SUPPORT_MANAGER);

        // Enough to answer "did my image ever finish?".
        $this->assertTrue($support->can('media.view'));
        // Not enough to reach into a customer's content.
        $this->assertFalse($support->can('media.delete_any'));
        $this->assertFalse($support->can('media.manage'));

        $this->actingAs($support)->get(ImageGenerationResource::getUrl('index'))->assertOk();
    }

    public function test_the_settings_screen_saves_and_audits(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(ImagesAndVoice::class)
            ->set('values.images__max_per_day', 7)
            ->call('save');

        $this->assertSame(7, (int) settings('images.max_per_day'));

        // Authorise, validate, AUDIT — all three or it does not ship.
        $this->assertDatabaseHas('activity_logs', ['action' => 'media.settings_updated']);
    }

    public function test_the_settings_screen_refuses_a_value_the_registry_rejects(): void
    {
        $admin = $this->admin();
        $before = (int) settings('voice.max_recording_seconds');

        Livewire::actingAs($admin)
            ->test(ImagesAndVoice::class)
            ->set('values.voice__max_recording_seconds', 99999)
            ->call('save');

        $this->assertSame($before, (int) settings('voice.max_recording_seconds'),
            'A value outside the registry rules was written anyway.');
    }

    public function test_a_reader_without_manage_cannot_write(): void
    {
        $support = $this->admin(PermissionRegistry::SUPPORT_MANAGER);
        $before = (int) settings('images.max_per_day');

        Livewire::actingAs($support)
            ->test(ImagesAndVoice::class)
            ->set('values.images__max_per_day', 3)
            ->call('save');

        $this->assertSame($before, (int) settings('images.max_per_day'));
    }

    public function test_the_screen_says_whether_the_features_can_actually_work(): void
    {
        // A switch that is on with no model behind it is the most confusing
        // state this product has.
        AiModel::query()->update(['is_enabled' => false]);

        Livewire::actingAs($this->admin())
            ->test(ImagesAndVoice::class)
            ->assertSee('Nothing available');
    }
}
