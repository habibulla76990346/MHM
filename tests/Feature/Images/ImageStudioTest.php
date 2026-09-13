<?php

namespace Tests\Feature\Images;

use App\Domains\AI\Models\AiModel;
use App\Domains\AI\Support\Capability;
use App\Domains\Images\Models\ImageGeneration;
use App\Domains\Security\Services\PermissionRegistry;
use App\Livewire\ImageStudio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\GeneratesMedia;
use Tests\TestCase;

/**
 * The studio and the gallery (§16).
 *
 * WHAT THE SCREEN MUST NEVER DO is show one customer another's pictures, or
 * offer a control that cannot work. Both are asserted here; how it looks at
 * six widths is the responsive gate's job, and whether the button actually
 * generates is the browser gate's.
 */
class ImageStudioTest extends TestCase
{
    use GeneratesMedia, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpMediaFixtures();
        $this->installMediaStub();
        $this->mediaModel(Capability::IMAGE_GENERATION, 'painter-1');

        $this->user->forceFill(['email_verified_at' => now()])->save();
        $this->user = $this->user->fresh();
    }

    public function test_the_studio_generates_and_the_gallery_shows_it(): void
    {
        Livewire::actingAs($this->user)
            ->test(ImageStudio::class)
            ->set('prompt', 'a quiet street at dawn')
            ->call('generate')
            ->assertSet('error', '');

        $this->assertSame(1, ImageGeneration::count());

        Livewire::actingAs($this->user)
            ->test(ImageStudio::class)
            ->assertSee('a quiet street at dawn');
    }

    public function test_the_gallery_shows_only_this_customers_pictures(): void
    {
        $stranger = User::factory()->create();
        $stranger->assignRole(PermissionRegistry::CUSTOMER);

        ImageGeneration::create([
            'user_id' => $stranger->getKey(),
            'prompt' => 'somebody elses secret picture',
            'status' => ImageGeneration::COMPLETED,
        ]);

        Livewire::actingAs($this->user)
            ->test(ImageStudio::class)
            ->assertDontSee('somebody elses secret picture');
    }

    public function test_a_refusal_is_shown_rather_than_thrown(): void
    {
        settings()->set('images.enabled', false);

        Livewire::actingAs($this->user)
            ->test(ImageStudio::class)
            ->set('prompt', 'anything at all')
            ->call('generate')
            ->assertSet('error', 'Image generation is switched off.');
    }

    public function test_the_form_is_not_offered_when_nothing_could_serve_it(): void
    {
        // A control that always fails teaches the customer the product is
        // broken, not that an administrator has not finished setting it up.
        AiModel::query()->update(['is_enabled' => false]);

        Livewire::actingAs($this->user)
            ->test(ImageStudio::class)
            ->assertSee('No image model is available yet');
    }

    public function test_deleting_an_image_deletes_its_bytes_too(): void
    {
        Livewire::actingAs($this->user)
            ->test(ImageStudio::class)
            ->set('prompt', 'a heron')
            ->call('generate');

        $generation = ImageGeneration::first()->fresh('file');
        $path = $generation->file->path;

        Livewire::actingAs($this->user)
            ->test(ImageStudio::class)
            ->call('remove', $generation->uuid);

        $this->assertFalse(Storage::disk('private')->exists($path),
            'The gallery entry went and the picture stayed on disk.');
        $this->assertSame(0, ImageGeneration::count());
    }

    public function test_deleting_somebody_elses_image_does_nothing(): void
    {
        $stranger = User::factory()->create();

        $theirs = ImageGeneration::create([
            'user_id' => $stranger->getKey(),
            'prompt' => 'theirs',
            'status' => ImageGeneration::COMPLETED,
        ]);

        Livewire::actingAs($this->user)
            ->test(ImageStudio::class)
            ->call('remove', $theirs->uuid);

        $this->assertSame(1, ImageGeneration::count());
    }

    public function test_reusing_a_prompt_fills_the_form_rather_than_generating(): void
    {
        Livewire::actingAs($this->user)
            ->test(ImageStudio::class)
            ->call('reuse', 'a heron at dusk')
            ->assertSet('prompt', 'a heron at dusk');

        $this->assertSame(0, ImageGeneration::count(), 'Reusing a prompt must not spend credits.');
    }

    public function test_the_images_page_needs_a_signed_in_customer(): void
    {
        $this->get(route('images'))->assertRedirect(route('login'));

        $this->actingAs($this->user)->get(route('images'))->assertOk();
    }
}
