<?php

namespace Tests\Feature\Diagnostics;

use App\Domains\Branding\Services\BrandAssetPublisher;
use Tests\TestCase;

/**
 * The Phase 0 deployment gate.
 *
 * Getting the document root wrong on cPanel exposes .env, source code and
 * dependencies to the public internet. This is the single most damaging
 * deployment mistake available, so it fails the phase rather than being
 * left to a checklist.
 */
class DeploymentSecurityTest extends TestCase
{
    public function test_sensitive_files_live_outside_the_public_directory(): void
    {
        $public = public_path();

        foreach ([
            '.env', '.env.example', 'composer.json', 'composer.lock',
            'artisan', 'package.json', 'phpunit.xml',
        ] as $file) {
            $this->assertFileDoesNotExist(
                $public.'/'.$file,
                "[$file] sits inside the web root. Anyone could download it."
            );
        }

        foreach (['vendor', 'storage', 'app', 'config', 'database', 'routes', 'tests', '.git'] as $dir) {
            $this->assertDirectoryDoesNotExist(
                $public.'/'.$dir,
                "[$dir/] sits inside the web root and is publicly reachable."
            );
        }
    }

    public function test_requesting_dotenv_over_http_does_not_return_it(): void
    {
        // Laravel's router must not serve it; on a real server the document
        // root configuration is what enforces this, and the diagnostics
        // security check verifies it over HTTP at runtime.
        $response = $this->get('/.env');

        $this->assertNotEquals(200, $response->getStatusCode(),
            'The .env file is reachable over HTTP. Every credential is exposed.');
    }

    public function test_debug_mode_is_disabled_when_not_in_local_environment(): void
    {
        if (app()->environment('local', 'testing')) {
            $this->markTestSkipped('APP_DEBUG is expected during local development.');
        }

        $this->assertFalse(config('app.debug'),
            'APP_DEBUG is on outside local. Stack traces would leak configuration to visitors.');
    }

    /**
     * Owner decision D-13 lets Aziv AI write DERIVED BRAND IMAGES into the web
     * root, and nothing else. That permission is the cost of not depending on a
     * symlink or on a PHP request per logo, so the containment is asserted
     * rather than trusted.
     *
     * Anything that appears under public/brand must be a raster image, or the
     * vector mark that ships with the product. An uploaded SVG reaching here
     * would be served same-origin and can carry script.
     */
    public function test_only_derived_images_are_published_into_the_web_root(): void
    {
        $directory = public_path('brand');

        if (! is_dir($directory)) {
            $this->markTestSkipped('No brand directory on this installation.');
        }

        $allowedExtensions = ['png', 'jpg', 'jpeg', 'webp', 'ico'];
        // Ships with the product, is authored by us, and is committed —
        // BrandAssetPublisher never writes an SVG.
        $shippedVectors = ['mark.svg'];

        foreach (new \DirectoryIterator($directory) as $entry) {
            if ($entry->isDot() || $entry->isDir()) {
                continue;
            }

            $name = $entry->getFilename();

            if (in_array($name, $shippedVectors, true)) {
                continue;
            }

            $extension = strtolower($entry->getExtension());

            $this->assertContains($extension, $allowedExtensions,
                "public/brand/{$name} is not a raster image. Only derived images may be published to the web root (D-13).");

            // And it must really be an image, not merely named like one.
            $this->assertNotFalse(@getimagesize($entry->getPathname()),
                "public/brand/{$name} has an image extension but is not an image.");
        }
    }

    /**
     * The publisher must have no way to write outside public/brand, whatever
     * it is handed. A purpose or path that escaped would put an arbitrary file
     * anywhere in the web root.
     */
    public function test_the_publisher_refuses_to_delete_outside_the_brand_directory(): void
    {
        $publisher = new BrandAssetPublisher;

        $canary = public_path('robots.txt');
        $this->assertFileExists($canary, 'This test needs a file in the web root to try to delete.');

        foreach ([
            '../robots.txt',
            'brand/../robots.txt',
            'brand/../../.env',
            '/etc/passwd',
            'robots.txt',
        ] as $attempt) {
            $publisher->unpublish($attempt);
        }

        $this->assertFileExists($canary, 'The publisher deleted a file outside public/brand.');
        $this->assertFileExists(base_path('.env'));
    }

    public function test_an_application_key_is_set(): void
    {
        // APP_KEY decrypts stored provider credentials. Without it, nothing
        // encrypted can be read back — see the migration checklist.
        $this->assertNotEmpty(config('app.key'));
    }
}
