<?php

namespace Tests\Feature\Deployment;

use App\Console\Commands\ReleaseCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

/**
 * The release package (Owner Addendum E §2), read adversarially.
 *
 * A RELEASE ARCHIVE IS EMAILED. It goes into a control panel's file manager, a
 * downloads folder, a shared drive, and it stays there. So the interesting
 * question is not "does it contain the application" — it obviously does — but
 * "what else did it take with it".
 *
 * The archive is built ONCE for the whole suite, because building it is a
 * minute of work and every test here reads the same artefact.
 */
class ReleasePackageTest extends TestCase
{
    use RefreshDatabase;

    private static ?string $archive = null;

    private static ?string $output = null;

    public static function tearDownAfterClass(): void
    {
        if (self::$output && is_dir(self::$output)) {
            foreach (glob(self::$output.'/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir(self::$output);
        }

        self::$archive = null;
        self::$output = null;

        parent::tearDownAfterClass();
    }

    private function archive(): string
    {
        if (self::$archive !== null) {
            return self::$archive;
        }

        self::$output = sys_get_temp_dir().'/aziv-release-test';

        $this->artisan('aziv:release', ['--output' => self::$output])->assertExitCode(0);

        $found = glob(self::$output.'/*.zip') ?: [];

        $this->assertNotEmpty($found, 'The release command produced no archive.');

        return self::$archive = $found[0];
    }

    /** @return array<int, string> every path inside the archive */
    private function contents(): array
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($this->archive()) === true);

        $names = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $names[] = (string) $zip->getNameIndex($index);
        }

        $zip->close();

        return $names;
    }

    // -- what must be in it -------------------------------------------------------

    public function test_the_package_contains_everything_a_server_without_a_shell_needs(): void
    {
        $contents = $this->contents();

        foreach (ReleaseCommand::REQUIRED_IN_PACKAGE as $required) {
            $this->assertContains($required, $contents, $required.' is missing from the release package.');
        }
    }

    public function test_it_ships_the_dependencies_and_the_compiled_assets(): void
    {
        $contents = $this->contents();

        // The three commands an owner without a shell cannot run are
        // composer install, npm install and npm run build. Two of them are
        // solved by shipping the result.
        $this->assertTrue(
            collect($contents)->contains(fn ($p) => str_starts_with($p, 'vendor/laravel/framework/')),
            'The framework is not in the package, so it cannot run without Composer.',
        );
        $this->assertTrue(
            collect($contents)->contains(fn ($p) => str_starts_with($p, 'public/build/assets/')),
            'The compiled assets are missing, so the site would have no styling.',
        );
    }

    public function test_it_contains_the_directories_a_fresh_install_writes_into(): void
    {
        $contents = $this->contents();

        // Empty directories do not survive a zip unless they are added
        // explicitly, and their absence is a permission error on first boot
        // that reads like a broken download.
        foreach (['storage/logs/', 'storage/framework/cache/', 'bootstrap/cache/'] as $directory) {
            $this->assertTrue(
                collect($contents)->contains(fn ($p) => str_starts_with($p, $directory)),
                $directory.' is missing, so a fresh install cannot write to it.',
            );
        }
    }

    public function test_it_carries_its_own_instructions(): void
    {
        $this->assertContains('RELEASE.md', $this->contents());
        $this->assertContains('docs/19-backup-and-restore.md', $this->contents());
    }

    // -- what must NEVER be in it -----------------------------------------------------

    public function test_no_environment_file_travels_with_it(): void
    {
        // `.env` is every credential on the build machine: provider keys,
        // gateway secrets, the database password and APP_KEY itself.
        foreach ($this->contents() as $path) {
            if ($path === '.env.example') {
                // The opposite of a credential: it is the documentation an
                // owner copies, and it has to ship.
                continue;
            }

            $this->assertDoesNotMatchRegularExpression('/(^|\/)\.env($|\.)/', $path,
                'An environment file is in the release archive: '.$path);
        }

        // The template, on the other hand, is the documentation.
        $this->assertContains('.env.example', $this->contents());
    }

    public function test_no_customer_data_no_logs_and_no_database_travel_with_it(): void
    {
        foreach ($this->contents() as $path) {
            // The empty directory itself has to ship — a fresh install writes
            // into it, and an absent directory is a permission error on first
            // boot that reads like a broken download. Anything INSIDE it is
            // somebody's file.
            if (str_ends_with($path, '/') || str_ends_with($path, '/.gitignore')) {
                continue;
            }

            $this->assertStringStartsNotWith('storage/app/', $path, 'Uploaded files are in the archive: '.$path);
            $this->assertStringStartsNotWith('storage/logs/laravel', $path, 'Logs are in the archive: '.$path);
            $this->assertStringNotContainsString('.sqlite', $path, 'A database is in the archive: '.$path);
        }
    }

    /**
     * Nothing a TEST RUN left behind travels with it either.
     *
     * PHPUnit's fake disks live under `storage/framework/testing`, and they
     * are not empty scratch: the suite generates images, synthesises audio and
     * stages Livewire uploads there, using the same code paths that write
     * customer content. The first package built after a suite run carried a
     * `.webm` recording and a staged spreadsheet. Nobody would have looked.
     */
    public function test_nothing_a_test_run_left_behind_travels_with_it(): void
    {
        $offenders = array_values(array_filter(
            $this->contents(),
            fn (string $path) => str_starts_with($path, 'storage/framework/testing')
                || str_contains($path, 'livewire-tmp')
                || str_contains($path, '.phpunit.cache'),
        ));

        $this->assertSame([], $offenders, implode("\n", array_merge(
            ['These came out of a test run and are inside a package meant for a stranger:'],
            $offenders,
        )));
    }

    public function test_no_version_control_history_travels_with_it_at_any_depth(): void
    {
        // Composer installing from source leaves a full clone inside every
        // package. On this build machine that turned a 40MB archive into three
        // gigabytes of other projects' history — enormous, and somebody else's
        // repository inside a product download.
        foreach ($this->contents() as $path) {
            $this->assertStringNotContainsString('/.git/', '/'.$path,
                'Version control history is in the archive: '.$path);
        }
    }

    public function test_the_install_lock_does_not_travel_with_it(): void
    {
        // A lock file in the package would make a fresh download refuse to
        // install, with a 404 and no explanation.
        $this->assertNotContains('storage/installed.json', $this->contents());
    }

    public function test_the_test_suite_does_not_ship_to_production(): void
    {
        foreach ($this->contents() as $path) {
            $this->assertStringStartsNotWith('tests/', $path);
            $this->assertNotSame('phpunit.xml', $path);
        }
    }

    // -- the manifest ----------------------------------------------------------------

    public function test_the_manifest_lets_an_owner_prove_the_download_is_intact(): void
    {
        $this->archive();

        $manifest = json_decode((string) file_get_contents(
            (glob(self::$output.'/*-manifest.json') ?: [''])[0],
        ), true);

        $this->assertSame(config('aziv.version'), $manifest['version']);
        $this->assertSame(hash_file('sha256', $this->archive()), $manifest['sha256']);
        $this->assertGreaterThan(0, $manifest['files']);
        // And it says what was left out, so the exclusions are inspectable
        // rather than a claim in a docblock.
        $this->assertNotEmpty($manifest['excludes']['directories']);
    }

    // -- the build refuses to produce something unusable ---------------------------------

    // -- the SQL exports (DL-5) ---------------------------------------------------

    public function test_it_generates_a_version_stamped_schema_export(): void
    {
        $this->archive();

        $found = glob(self::$output.'/*-schema-only.sql') ?: [];

        $this->assertNotEmpty($found, 'No schema export was produced.');

        $sql = (string) file_get_contents($found[0]);

        $this->assertStringContainsString('Aziv AI '.config('aziv.version'), $sql,
            'The export is not version-stamped, so an owner cannot tell which build it came from.');
        $this->assertStringContainsString('CREATE TABLE', $sql);
        $this->assertStringNotContainsString('INSERT INTO', $sql,
            'A schema-only export is carrying rows.');
    }

    public function test_the_clean_install_export_carries_reference_data_and_no_account(): void
    {
        $this->archive();

        $found = glob(self::$output.'/*-clean-install.sql') ?: [];

        if ($found === []) {
            // Honest rather than green: it needs a scratch database, and the
            // build user may not be allowed to create one. The web installer
            // and `php artisan migrate` both still work without this file.
            $this->markTestSkipped(
                'No clean-install export was produced — the build user cannot create a scratch database. '
                ."Grant it: GRANT ALL ON \`aziv\\_release\\_%\`.* TO <build user>;",
            );
        }

        $sql = (string) file_get_contents($found[0]);

        // Reference data an owner cannot be expected to type.
        foreach (['roles', 'permissions', 'themes', 'countries', 'currencies'] as $table) {
            $this->assertStringContainsString('INSERT INTO `'.$table.'`', $sql,
                'The clean install has no '.$table.', so importing it leaves a platform that cannot run.');
        }

        // AND NOTHING ELSE. This is the assertion that matters: the file is
        // emailed, and it was generated on a machine that has real data on it.
        foreach ([
            'users', 'ai_provider_credentials', 'payment_gateway_credentials',
            'sessions', 'cache', 'personal_access_tokens',
            'chat_messages', 'invoices', 'payments', 'credit_ledger_entries',
        ] as $table) {
            $this->assertStringNotContainsString('INSERT INTO `'.$table.'`', $sql,
                'The clean-install export is carrying rows from `'.$table.'` — that is the build machine\'s data '
                .'travelling inside a product download.');
        }

        // The structure still has to be there, or the import is useless.
        $this->assertStringContainsString('CREATE TABLE `users`', $sql);
    }

    public function test_a_build_with_no_compiled_assets_fails_rather_than_shipping(): void
    {
        $manifest = public_path('build/manifest.json');
        $backup = $manifest.'.handover-backup';

        $this->assertFileExists($manifest, 'Run npm run build before this suite.');
        rename($manifest, $backup);

        try {
            // An owner without a shell cannot run npm. A package missing the
            // compiled assets is a site with no styling and no way to fix it.
            $this->artisan('aziv:release', ['--output' => sys_get_temp_dir().'/aziv-release-broken'])
                ->assertExitCode(1);
        } finally {
            rename($backup, $manifest);
        }
    }
}
