<?php

namespace Tests\Feature\Images;

use App\Domains\AI\Support\Capability;
use App\Domains\Files\Models\File;
use App\Domains\Images\Models\ImageGeneration;
use App\Domains\Images\Services\ImageService;
use App\Domains\Images\Services\MediaRetentionService;
use App\Domains\Voice\Models\VoiceJob;
use App\Domains\Voice\Services\VoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\GeneratesMedia;
use Tests\TestCase;

/**
 * Retention (§16, §18).
 *
 * THE TWO FEATURES FORGET DIFFERENT THINGS, and that asymmetry is the whole
 * reason this has its own suite. An image IS what the customer made, so it
 * goes entirely. Audio is a large file of somebody's voice whose lasting
 * product is the TRANSCRIPT — a chat message they own — so retention deletes
 * the audio and keeps the record of what it cost.
 *
 * The failure this prevents is the quiet one: a row deleted while its file
 * stays on disk for ever. That is a deletion the customer believes happened
 * and did not, and it is worse than not deleting at all, because nobody looks
 * again.
 */
class MediaRetentionTest extends TestCase
{
    use GeneratesMedia, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpMediaFixtures();
        $this->installMediaStub();
        $this->mediaModel(Capability::IMAGE_GENERATION, 'painter-1');
        $this->mediaModel(Capability::TRANSCRIPTION, 'ears-1');
    }

    private function sweep(): array
    {
        return app(MediaRetentionService::class)->sweep();
    }

    public function test_an_expired_image_takes_its_bytes_with_it(): void
    {
        settings()->set('images.retention_days', 7);

        app(ImageService::class)->request($this->user, 'a heron');
        $generation = ImageGeneration::first()->fresh('file');
        $path = $generation->file->path;

        $this->assertTrue(Storage::disk('private')->exists($path));

        $this->travelTo(now()->addDays(8));
        $this->assertSame(['images' => 1, 'audio' => 0], $this->sweep());

        $this->assertFalse(Storage::disk('private')->exists($path),
            'The row went and the picture stayed on disk for ever.');
        $this->assertSame(0, ImageGeneration::count());
        $this->assertSame(0, File::whereKey($generation->file_id)->count());
    }

    public function test_an_image_with_no_expiry_is_never_swept(): void
    {
        // Zero means keep until somebody deletes it. Deleting a customer's
        // pictures because nobody chose a number would be the wrong default.
        settings()->set('images.retention_days', 0);

        app(ImageService::class)->request($this->user, 'a kingfisher');

        $this->travelTo(now()->addYears(5));
        $this->sweep();

        $this->assertSame(1, ImageGeneration::count());
    }

    public function test_expired_audio_goes_and_its_record_stays(): void
    {
        settings()->set('voice.retention_days', 7);

        $job = app(VoiceService::class)->transcribe($this->user, self::realWebm(), seconds: 4.0);
        $job = $job->fresh('file');
        $path = $job->file->path;
        $transcript = $job->text;

        $this->travelTo(now()->addDays(8));
        $this->assertSame(['images' => 0, 'audio' => 1], $this->sweep());

        $this->assertFalse(Storage::disk('private')->exists($path));

        $job = VoiceJob::firstOrFail();

        // The row survives: the cost happened and the owner's reporting needs
        // it, and the customer loses nothing they can see.
        $this->assertNull($job->file_id);
        $this->assertSame($transcript, $job->text);
        $this->assertNull($job->expires_at, 'A swept row must not be swept again every night.');
    }

    public function test_a_missing_file_does_not_stop_the_sweep(): void
    {
        settings()->set('images.retention_days', 1);

        app(ImageService::class)->request($this->user, 'a heron');
        $generation = ImageGeneration::first()->fresh('file');

        // Somebody cleaned the disk by hand. The row must still be dealt with,
        // or it stays expiring for ever and the sweep logs the same failure
        // every night.
        Storage::disk('private')->delete($generation->file->path);

        $this->travelTo(now()->addDays(2));
        $this->sweep();

        $this->assertSame(0, ImageGeneration::count());
    }
}
