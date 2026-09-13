<?php

namespace Tests\Feature\Images;

use App\Domains\AI\Support\Capability;
use App\Domains\Files\Exceptions\UploadRejected;
use App\Domains\Files\Models\File;
use App\Domains\Files\Services\FileStorage;
use App\Domains\Images\Models\ImageGeneration;
use App\Domains\Images\Services\ImageService;
use App\Domains\Security\Services\PermissionRegistry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\GeneratesMedia;
use Tests\TestCase;

/**
 * The one route that renders a stored file INLINE, and what stops it becoming
 * a hole in the upload rules (§16, §18, Owner Addendum H).
 *
 * WHY THIS ROUTE IS DANGEROUS AND WHY IT EXISTS ANYWAY. Every other file in
 * the platform is served as an attachment, deliberately: stored HTML or SVG
 * rendered inline would execute in this application's origin with the
 * customer's session. But a generated picture has to appear in a gallery and
 * speech has to play in an `<audio>` element, and neither works as a download.
 * So inline serving exists behind four conditions, and every one of them is
 * asserted here — because three out of four is a vulnerability.
 */
class MediaSecurityTest extends TestCase
{
    use GeneratesMedia, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpMediaFixtures();
        $this->installMediaStub();
        $this->mediaModel(Capability::IMAGE_GENERATION, 'painter-1');
    }

    private function generatedImage(): ImageGeneration
    {
        app(ImageService::class)->request($this->user, 'a heron');

        return ImageGeneration::first()->fresh('file');
    }

    // -- the four conditions ----------------------------------------------------

    public function test_a_generated_image_renders_inline_for_its_owner(): void
    {
        $generation = $this->generatedImage();

        $response = $this->actingAs($this->user)->get(route('media.show', $generation->file));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/png');
        $this->assertStringStartsWith('inline', $response->headers->get('Content-Disposition'));

        // The headers assume the other three conditions were wrong anyway.
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
        // Private: one customer's content, and a shared cache holding it is a
        // way for the next person to be served it.
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
    }

    public function test_somebody_elses_image_is_refused(): void
    {
        $generation = $this->generatedImage();

        $stranger = User::factory()->create();
        $stranger->assignRole(PermissionRegistry::CUSTOMER);

        $this->actingAs($stranger->fresh())
            ->get(route('media.show', $generation->file))
            ->assertForbidden();
    }

    public function test_an_ordinary_upload_never_renders_inline_however_harmless_it_looks(): void
    {
        // A PNG the CUSTOMER uploaded. Same bytes, same type, same owner — and
        // it still may not render inline, because the purpose is what says
        // whether the bytes went through the upload rules or the stricter
        // machine-written ones.
        //
        // THE BYTES ARE REALLY ON THE DISK. Without them the controller's
        // existence check would 404 first and this test would pass with the
        // purpose condition deleted — which is exactly what sabotage found.
        $file = $this->fileOnDisk('uploads/harmless.png', self::realPng(), 'image/png', 'png', 'attachment');

        $this->actingAs($this->user)->get(route('media.show', $file))->assertNotFound();
    }

    public function test_a_type_that_can_carry_script_never_renders_inline(): void
    {
        // An SVG is a document that can run JavaScript, not a picture. Even
        // labelled with a purpose the platform writes, and even really present
        // on the disk, it is refused.
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
        $file = $this->fileOnDisk('generated/evil.svg', $svg, 'image/svg+xml', 'svg', 'image_generation');

        $this->actingAs($this->user)->get(route('media.show', $file))->assertNotFound();
    }

    /**
     * A file row whose bytes are genuinely on the disk.
     *
     * WHY THIS MATTERS MORE THAN IT LOOKS. `MediaController` refuses a file
     * that is not on disk, and that check runs alongside the four conditions
     * this suite exists to prove. A fixture that only creates a ROW passes
     * every one of these tests with the conditions deleted — the 404 comes
     * from the missing file, not from the rule. Sabotage found exactly that.
     */
    private function fileOnDisk(string $path, string $bytes, string $mime, string $extension, string $purpose): File
    {
        Storage::disk('private')->put($path, $bytes);

        return File::create([
            'user_id' => $this->user->getKey(),
            'disk' => 'private',
            'path' => $path,
            'stored_name' => basename($path),
            'original_name' => basename($path),
            'detected_mime' => $mime,
            'extension' => $extension,
            'size_bytes' => strlen($bytes),
            'checksum' => hash('sha256', $bytes),
            'purpose' => $purpose,
        ]);
    }

    public function test_a_quarantined_file_is_refused_even_to_its_owner(): void
    {
        $generation = $this->generatedImage();
        $generation->file->update(['quarantined_at' => now()]);

        // 403 rather than 404: the ordinary file policy refuses a quarantined
        // file before this controller's own check is reached. Both locks are
        // on the same door and the first one holds.
        $this->actingAs($this->user)
            ->get(route('media.show', $generation->file->fresh()))
            ->assertForbidden();
    }

    public function test_signing_out_is_enough_to_lose_access(): void
    {
        $generation = $this->generatedImage();

        // A URL is not authorisation, and it is not a bearer token either.
        $this->get(route('media.show', $generation->file))->assertRedirect(route('login'));
    }

    // -- what the platform may write at all -------------------------------------

    public function test_bytes_are_typed_from_their_content_not_from_a_claim(): void
    {
        $storage = app(FileStorage::class);

        // An HTML error page a provider returned with a 200.
        $this->expectException(UploadRejected::class);

        $storage->storeGenerated('<!doctype html><script>alert(1)</script>', $this->user, 'image_generation');
    }

    public function test_a_purpose_that_declares_nothing_may_write_nothing(): void
    {
        // Adding a feature must mean declaring what it may write. Falling back
        // to "anything" would make this method the hole the upload rules exist
        // to close.
        $this->expectException(UploadRejected::class);

        app(FileStorage::class)->storeGenerated(self::realPng(), $this->user, 'some_new_feature');
    }

    public function test_a_recording_may_not_be_an_image_and_an_image_may_not_be_audio(): void
    {
        $storage = app(FileStorage::class);

        // Each purpose has its OWN list. Sharing one would mean widening it
        // for images widened it for recordings too.
        $this->expectException(UploadRejected::class);

        $storage->storeGenerated(self::realPng(), $this->user, 'voice_recording');
    }

    public function test_oversized_bytes_are_refused_before_they_reach_the_disk(): void
    {
        $this->expectException(UploadRejected::class);

        // A provider returning a video, a redirect loop, or a wrong
        // Content-Length. The ceiling is not the administrator's upload limit:
        // these bytes never passed through it.
        app(FileStorage::class)->storeGenerated(
            self::realPng().str_repeat('x', 26 * 1024 * 1024),
            $this->user,
            'image_generation',
        );
    }

    public function test_a_generated_file_lands_on_the_private_disk_with_a_generated_name(): void
    {
        $file = app(FileStorage::class)->storeGenerated(
            self::realPng(),
            $this->user,
            'image_generation',
            "../../etc/passwd\n",
        );

        $this->assertSame('private', $file->disk);
        // No part of the caller's display name reaches the path.
        $this->assertStringStartsWith('generated/'.$this->user->getKey().'/', $file->path);
        $this->assertStringNotContainsString('..', $file->path);
        $this->assertStringNotContainsString('/', $file->original_name);
        $this->assertStringEndsWith('.png', $file->stored_name);
    }
}
