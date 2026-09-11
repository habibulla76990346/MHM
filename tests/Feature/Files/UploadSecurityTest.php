<?php

namespace Tests\Feature\Files;

use App\Domains\Files\Exceptions\UploadRejected;
use App\Domains\Files\Models\File;
use App\Domains\Files\Services\FileStorage;
use App\Domains\Files\Services\UploadValidator;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The nine Phase 1 controls (Owner Addendum H).
 *
 * Each test names the attack it defeats. These are the assertions that make
 * "upload security is built in from the beginning" a fact rather than a claim.
 */
class UploadSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('private');
        $this->user = User::factory()->create();
    }

    private function validator(): UploadValidator
    {
        return app(UploadValidator::class);
    }

    private function storage(): FileStorage
    {
        return app(FileStorage::class);
    }

    /** Writes a file whose real content type differs from its name. */
    private function disguised(string $name, string $content, string $claimedMime): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'aziv');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, $claimedMime, null, true);
    }

    /** US-2 / US-3: a PHP script renamed .jpg, sent with an image content type. */
    public function test_a_php_script_renamed_as_an_image_is_rejected(): void
    {
        $this->expectException(UploadRejected::class);

        $this->validator()->validate(
            $this->disguised('avatar.jpg', "<?php system(\$_GET['c']); ?>", 'image/jpeg')
        );
    }

    /** US-3: double extensions are rejected, not normalised. */
    public function test_a_double_extension_is_rejected(): void
    {
        $this->expectException(UploadRejected::class);

        $this->validator()->validate(
            UploadedFile::fake()->create('invoice.php.jpg', 8, 'image/jpeg')
        );
    }

    public function test_a_php_file_is_rejected_even_when_the_extension_is_allowlisted(): void
    {
        settings()->set('uploads.allowed_extensions', ['php', 'jpg']);

        $this->expectException(UploadRejected::class);

        $this->validator()->validate(UploadedFile::fake()->create('shell.php', 4, 'text/plain'));
    }

    /** US-1: anything not on the allowlist is refused. */
    public function test_a_type_outside_the_allowlist_is_rejected(): void
    {
        $this->expectException(UploadRejected::class);

        $this->validator()->validate(UploadedFile::fake()->create('archive.zip', 8, 'application/zip'));
    }

    /** US-7: SVG is active content — it can carry script. */
    public function test_svg_is_rejected_as_active_content(): void
    {
        settings()->set('uploads.allowed_extensions', ['svg', 'png']);

        $this->expectException(UploadRejected::class);

        $this->validator()->validate(
            $this->disguised('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'image/svg+xml')
        );
    }

    /** US-4 */
    public function test_a_file_over_the_configured_limit_is_rejected(): void
    {
        settings()->set('uploads.max_size_kb', 64);

        $this->expectException(UploadRejected::class);

        $this->validator()->validate(UploadedFile::fake()->create('big.pdf', 256, 'application/pdf'));
    }

    /** US-5: path traversal in the client filename must never reach the filesystem. */
    public function test_a_traversal_filename_cannot_escape_the_storage_directory(): void
    {
        $file = $this->storage()->store(
            UploadedFile::fake()->image('../../../../etc/passwd.png'),
            $this->user,
        );

        $this->assertStringNotContainsString('..', $file->path);
        $this->assertStringStartsWith('uploads/'.$this->user->getKey().'/', $file->path);
        // The original is kept for display, stripped of separators.
        $this->assertStringNotContainsString('/', $file->original_name);
    }

    /** US-5: the stored name is generated, never taken from the client. */
    public function test_the_stored_filename_is_generated(): void
    {
        $file = $this->storage()->store(UploadedFile::fake()->image('holiday-photo.png'), $this->user);

        $this->assertNotSame('holiday-photo.png', $file->stored_name);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}\.png$/', $file->stored_name);
        $this->assertSame('holiday-photo.png', $file->original_name);
    }

    /** US-6: uploads land on the private disk, never anywhere web-reachable. */
    public function test_uploads_are_stored_on_the_private_disk_outside_the_web_root(): void
    {
        $file = $this->storage()->store(UploadedFile::fake()->image('doc.png'), $this->user);

        $this->assertSame('private', $file->disk);
        Storage::disk('private')->assertExists($file->path);

        // Nothing may appear under public/ — there must be no fetchable URL.
        $this->assertFileDoesNotExist(public_path($file->path));
        $this->assertFileDoesNotExist(public_path('storage/'.$file->path));
    }

    /** US-9: changing the id in the URL must not return someone else's file. */
    public function test_one_user_cannot_download_another_users_file(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();

        $file = $this->storage()->store(UploadedFile::fake()->image('private.png'), $owner);

        $this->actingAs($stranger)
            ->get(route('files.show', $file))
            ->assertForbidden();

        $this->actingAs($owner)
            ->get(route('files.show', $file))
            ->assertOk();
    }

    public function test_an_anonymous_visitor_cannot_download_a_file(): void
    {
        $file = $this->storage()->store(UploadedFile::fake()->image('private.png'), $this->user);

        $this->get(route('files.show', $file))->assertRedirect(route('login'));
    }

    /** US-9: downloads never render inline, so stored markup cannot execute. */
    public function test_downloads_are_forced_as_attachments_with_nosniff(): void
    {
        $file = $this->storage()->store(UploadedFile::fake()->image('doc.png'), $this->user);

        $response = $this->actingAs($this->user)->get(route('files.show', $file));

        $response->assertOk();
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    /** Files are addressed by UUID so ids are not enumerable. */
    public function test_files_are_addressed_by_uuid_not_sequential_id(): void
    {
        $file = $this->storage()->store(UploadedFile::fake()->image('doc.png'), $this->user);

        $this->assertSame('uuid', (new File())->getRouteKeyName());
        $this->assertStringContainsString($file->uuid, route('files.show', $file));
        $this->assertStringNotContainsString('/'.$file->id, route('files.show', $file));
    }

    /**
     * US-10 / US-11: with no scanner configured the verdict is 'skipped', never
     * 'clean'. A reassuring green tick on a check that never ran is worse than
     * no scanning at all.
     */
    public function test_an_unscanned_file_is_recorded_as_skipped_not_clean(): void
    {
        $file = $this->storage()->store(UploadedFile::fake()->image('doc.png'), $this->user);

        $this->assertSame('skipped', $file->scan_verdict);
        $this->assertNotSame('clean', $file->scan_verdict);
        $this->assertSame('none', $file->scanner_key);
    }

    /** A quarantined file is withheld from everyone, including its owner. */
    public function test_a_quarantined_file_cannot_be_downloaded_even_by_its_owner(): void
    {
        $file = $this->storage()->store(UploadedFile::fake()->image('doc.png'), $this->user);
        $file->update(['quarantined_at' => now()]);

        $this->actingAs($this->user)
            ->get(route('files.show', $file))
            ->assertForbidden();
    }

    /** The real type is read from the bytes, not from the request. */
    public function test_the_declared_content_type_is_recorded_but_not_trusted(): void
    {
        $file = $this->storage()->store(
            UploadedFile::fake()->image('photo.png'),
            $this->user,
        );

        $this->assertStringContainsString('image/', $file->detected_mime);
        $this->assertNotEmpty($file->checksum);
    }
}
