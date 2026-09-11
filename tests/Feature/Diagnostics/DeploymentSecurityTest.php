<?php

namespace Tests\Feature\Diagnostics;

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

    public function test_an_application_key_is_set(): void
    {
        // APP_KEY decrypts stored provider credentials. Without it, nothing
        // encrypted can be read back — see the migration checklist.
        $this->assertNotEmpty(config('app.key'));
    }
}
