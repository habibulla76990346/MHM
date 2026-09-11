<?php

namespace Tests\Feature\Branding;

use App\Domains\Branding\Services\BrandAssetPublisher;
use App\Domains\Branding\Services\BrandAssetRejected;
use App\Domains\Branding\Services\BrandingService;
use App\Domains\Branding\Support\BrandAsset;
use App\Domains\Files\Models\File;
use App\Domains\Files\Services\FileStorage;
use App\Domains\Security\Services\PermissionRegistry;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Owner decision D-13: derived brand images may be written to the web root,
 * and nothing else may. These hold the implementation to that.
 */
class BrandingTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> written during a test, removed afterwards */
    private array $published = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function tearDown(): void
    {
        // The web root is real, not a fake disk — that is the point of D-13 —
        // so anything a test publishes has to be cleaned up by the test.
        foreach ($this->published as $path) {
            @unlink(public_path($path));
        }

        parent::tearDown();
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(PermissionRegistry::SUPER_ADMIN);

        return $user->fresh();
    }

    private function branding(): BrandingService
    {
        return app(BrandingService::class);
    }

    /**
     * An unsaved File record. The publisher only reads its attributes and its
     * bytes, so it does not need to be persisted — and building one by hand
     * keeps these tests about the publisher rather than about the upload
     * pipeline, which has its own tests.
     */
    private function fileRecord(array $attributes = []): File
    {
        return new File(array_merge([
            'disk' => 'private',
            'path' => 'uploads/example.png',
            'stored_name' => 'example.png',
            'original_name' => 'example.png',
            'detected_mime' => 'image/png',
            'declared_mime' => 'image/png',
            'extension' => 'png',
            'size_bytes' => 100,
            'checksum' => str_repeat('a', 64),
            'purpose' => 'brand_mark',
        ], $attributes));
    }

    private function track(string $path): string
    {
        $this->published[] = $path;

        return $path;
    }

    // -- defaults ------------------------------------------------------------

    public function test_every_shipped_default_actually_exists_in_the_web_root(): void
    {
        // A default that 404s is worse than no default: the fallback is what
        // every other guarantee here rests on.
        foreach (BrandAsset::all() as $purpose => $asset) {
            $this->assertFileExists(public_path($asset['default']),
                "The shipped default for {$purpose} is missing from the web root.");
        }
    }

    public function test_an_untouched_installation_serves_the_shipped_artwork(): void
    {
        foreach (BrandAsset::purposes() as $purpose) {
            $this->assertSame(BrandAsset::all()[$purpose]['default'], $this->branding()->path($purpose));
            $this->assertFalse($this->branding()->isCustomised($purpose));
        }
    }

    /**
     * A deploy that did not copy public/brand, or a file removed by hand,
     * must degrade to the shipped artwork rather than to a broken image.
     */
    public function test_a_published_file_that_has_vanished_falls_back(): void
    {
        settings()->set(BrandAsset::settingKey('favicon'), 'brand/does-not-exist.png');

        $this->assertSame(BrandAsset::all()['favicon']['default'], $this->branding()->path('favicon'));
    }

    // -- publishing ----------------------------------------------------------

    public function test_replacing_an_asset_stores_privately_and_publishes_a_derived_copy(): void
    {
        $admin = $this->admin();

        $result = $this->branding()->replace(
            'favicon',
            UploadedFile::fake()->image('my-icon.png', 64, 64),
            $admin,
        );

        $this->track($result['path']);

        // Published where the browser can reach it...
        $this->assertFileExists(public_path($result['path']));
        $this->assertStringStartsWith('brand/favicon-', $result['path']);
        $this->assertStringEndsWith('.png', $result['path']);
        $this->assertSame($result['path'], $this->branding()->path('favicon'));
        $this->assertTrue($this->branding()->isCustomised('favicon'));

        // ...and the record of truth is still on the private disk, having gone
        // through the ordinary Phase 1 pipeline.
        $file = File::where('purpose', 'brand_favicon')->latest('id')->first();
        $this->assertNotNull($file);
        $this->assertSame('private', $file->disk);
        $this->assertTrue(Storage::disk('private')->exists($file->path));
    }

    /** The published name is the content hash, so caching can never be wrong. */
    public function test_the_published_name_comes_from_the_content_not_the_client_filename(): void
    {
        $result = $this->branding()->replace(
            'mark',
            UploadedFile::fake()->image('../../etc/passwd.png', 64, 64),
            $this->admin(),
        );

        $this->track($result['path']);

        $file = File::where('purpose', 'brand_mark')->latest('id')->first();

        $this->assertSame('brand/mark-'.substr($file->checksum, 0, 8).'.png', $result['path']);
        $this->assertStringNotContainsString('passwd', $result['path']);
        $this->assertStringNotContainsString('..', $result['path']);
    }

    public function test_replacing_again_removes_the_file_it_replaced(): void
    {
        $first = $this->branding()->replace('mark', UploadedFile::fake()->image('a.png', 64, 64), $this->admin());
        $this->track($first['path']);

        $second = $this->branding()->replace('mark', UploadedFile::fake()->image('b.png', 128, 128), $this->admin());
        $this->track($second['path']);

        $this->assertNotSame($first['path'], $second['path']);
        $this->assertFileDoesNotExist(public_path($first['path']), 'The web root accumulates old uploads.');
        $this->assertFileExists(public_path($second['path']));
    }

    /**
     * D-09: the artwork Aziv AI ships with is the master and is always there
     * to return to. Resetting must never delete it.
     */
    public function test_resetting_restores_the_shipped_artwork_without_deleting_it(): void
    {
        $result = $this->branding()->replace('logo_light', UploadedFile::fake()->image('mine.png', 200, 80), $this->admin());
        $this->track($result['path']);

        $this->branding()->reset('logo_light');

        $this->assertSame(BrandAsset::all()['logo_light']['default'], $this->branding()->path('logo_light'));
        $this->assertFileExists(public_path(BrandAsset::all()['logo_light']['default']));
        $this->assertFileDoesNotExist(public_path($result['path']));
    }

    public function test_the_publisher_refuses_to_delete_a_shipped_default(): void
    {
        $default = BrandAsset::all()['favicon']['default'];

        settings()->set(BrandAsset::settingKey('favicon'), $default);
        $this->branding()->reset('favicon');

        $this->assertFileExists(public_path($default),
            'Resetting deleted the artwork it was supposed to fall back to.');
    }

    // -- what must never be published ---------------------------------------

    public function test_an_svg_is_never_published(): void
    {
        $file = $this->fileRecord(['detected_mime' => 'image/svg+xml', 'extension' => 'svg']);

        $this->expectException(BrandAssetRejected::class);
        $this->expectExceptionMessage('SVG is not accepted');

        app(BrandAssetPublisher::class)->publish($file, 'mark');
    }

    public function test_a_quarantined_file_is_never_published(): void
    {
        $upload = UploadedFile::fake()->image('ok.png', 64, 64);
        $stored = app(FileStorage::class)->store($upload, $this->admin(), 'brand_mark');

        $stored->update(['quarantined_at' => now()]);

        $this->expectException(BrandAssetRejected::class);
        $this->expectExceptionMessage('quarantined');

        app(BrandAssetPublisher::class)->publish($stored->fresh(), 'mark');
    }

    /**
     * The recorded mime is trusted at upload time; by publish time the bytes
     * are checked again. A script whose record claims image/png must not be
     * copied into the web root.
     */
    public function test_a_file_that_is_not_really_an_image_is_never_published(): void
    {
        Storage::disk('private')->put('uploads/fake.png', '<?php echo "pwned";');

        $file = $this->fileRecord(['path' => 'uploads/fake.png', 'checksum' => hash('sha256', 'fake')]);

        $this->expectException(BrandAssetRejected::class);
        $this->expectExceptionMessage('not a real image');

        app(BrandAssetPublisher::class)->publish($file, 'mark');
    }

    public function test_an_unknown_purpose_is_refused(): void
    {
        $file = $this->fileRecord();

        $this->expectException(BrandAssetRejected::class);

        app(BrandAssetPublisher::class)->publish($file, '../../../etc/passwd');
    }

    // -- the manifest --------------------------------------------------------

    public function test_the_pwa_manifest_is_built_from_branding_and_the_theme(): void
    {
        settings()->set('branding.app_name', 'Contoso AI');
        settings()->set('branding.short_name', 'Contoso');

        $response = $this->get('/manifest.webmanifest');

        $response->assertOk();
        $this->assertStringContainsString('application/manifest+json', $response->headers->get('Content-Type'));

        $manifest = $response->json();

        $this->assertSame('Contoso AI', $manifest['name']);
        $this->assertSame('Contoso', $manifest['short_name']);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/i', $manifest['theme_color']);

        // Every icon it advertises must actually be fetchable.
        foreach ($manifest['icons'] as $icon) {
            $path = parse_url($icon['src'], PHP_URL_PATH);
            $this->assertFileExists(public_path(ltrim($path, '/')), "Manifest advertises a missing icon: {$icon['src']}");
        }
    }

    public function test_the_manifest_is_reachable_without_signing_in(): void
    {
        // An installed app fetches this with no session at all.
        $this->get('/manifest.webmanifest')->assertOk();
    }
}
