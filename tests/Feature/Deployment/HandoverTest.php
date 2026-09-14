<?php

namespace Tests\Feature\Deployment;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

/**
 * THE HANDOVER TEST (Owner Addendum E, Phase 9 gate).
 *
 * *"Aziv AI installs on a clean, never-used server from the ZIP + SQL +
 * documentation alone — no SSH assumed, no access to the development
 * environment, no undocumented steps."*
 *
 * WHAT THIS ACTUALLY DOES, because the wording invites hand-waving: it builds
 * the release package, extracts it into an empty directory that is NOT this
 * repository, points it at an EMPTY database that has never held Aziv AI, and
 * then runs the application from that extracted copy — key generation,
 * migrations, seeders, the first administrator, and a health check — using
 * nothing but what came out of the archive.
 *
 * It is skipped, loudly, only where the environment genuinely cannot host it:
 * a separate database the test user may create in. On this build machine it
 * runs, and it is the difference between "we believe it installs" and "it
 * installed".
 *
 * @group handover
 */
class HandoverTest extends TestCase
{
    use RefreshDatabase;

    /** A database that has never held Aziv AI. */
    private const CLEAN_DATABASE = 'aziv_handover';

    private static ?string $extracted = null;

    private string $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = (string) config('database.default');

        if (! $this->cleanDatabaseIsReachable()) {
            $this->markTestSkipped(
                'No clean database available. Create one and grant the test user access: '
                .'CREATE DATABASE '.self::CLEAN_DATABASE.'; GRANT ALL ON '.self::CLEAN_DATABASE.'.* TO ...',
            );
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$extracted && is_dir(self::$extracted)) {
            exec('rm -rf '.escapeshellarg(self::$extracted));
        }

        self::$extracted = null;

        parent::tearDownAfterClass();
    }

    private function cleanDatabaseIsReachable(): bool
    {
        try {
            new \PDO(
                sprintf(
                    'mysql:host=%s;port=%s;dbname=%s',
                    config("database.connections.{$this->connection}.host"),
                    config("database.connections.{$this->connection}.port"),
                    self::CLEAN_DATABASE,
                ),
                (string) config("database.connections.{$this->connection}.username"),
                (string) config("database.connections.{$this->connection}.password"),
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => 3],
            );

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Build the package and extract it somewhere that is not this repository.
     *
     * The extracted copy is the ONLY thing the rest of this test may use. If
     * something needed is not in the archive, it is not there — which is the
     * whole point.
     */
    private function installation(): string
    {
        if (self::$extracted !== null) {
            return self::$extracted;
        }

        $output = sys_get_temp_dir().'/aziv-handover-build';
        $target = sys_get_temp_dir().'/aziv-handover-server';

        exec('rm -rf '.escapeshellarg($target));
        mkdir($target, 0755, true);

        $this->artisan('aziv:release', ['--output' => $output, '--skip-sql' => true])->assertExitCode(0);

        $archive = (glob($output.'/*.zip') ?: [''])[0];
        $this->assertNotSame('', $archive, 'No release archive was produced.');

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($archive) === true);
        $this->assertTrue($zip->extractTo($target), 'The archive could not be extracted.');
        $zip->close();

        foreach (glob($output.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($output);

        return self::$extracted = $target;
    }

    /**
     * Run a command inside the extracted copy, as an owner following the
     * documentation would.
     *
     * THE ENVIRONMENT IS EMPTIED FIRST, and that is not tidiness. PHPUnit
     * exports `DB_DATABASE=aziv_test`, `APP_ENV=testing`, `MAIL_MAILER=array`
     * and a dozen more as real process environment variables, and a child
     * process inherits every one of them — where Laravel reads them AHEAD of
     * the `.env` file it was given. Written the obvious way, this suite
     * installed nothing: it ran migrations against the test database that was
     * already migrated, seeded a database that was already seeded, and would
     * have passed with a `.env.example` that named no database at all.
     *
     * So the child gets `PATH` and `HOME` and nothing else. What it reads is
     * what came out of the archive, which is the only thing this test is
     * entitled to assert about.
     *
     * @return array{code: int, output: string}
     */
    private function inServer(string $command): array
    {
        $output = [];
        $code = 0;

        // The whole command goes INSIDE `sh -c`, and that is not stylistic
        // either. Written as a bare prefix, `env -i … printf x | php artisan`
        // clears the environment for `printf` and leaves `php` on the other
        // side of the pipe holding every variable PHPUnit exported — which is
        // exactly how the one step that reads stdin ended up running as
        // `testing` against the test database while every other step ran
        // correctly. A pipe is a shell operator; the scrub has to wrap it.
        $clean = 'env -i '
            .'PATH='.escapeshellarg((string) getenv('PATH')).' '
            .'HOME='.escapeshellarg((string) (getenv('HOME') ?: '/root')).' '
            .'sh -c '.escapeshellarg($command);

        exec('cd '.escapeshellarg($this->installation()).' && '.$clean.' 2>&1', $output, $code);

        return ['code' => $code, 'output' => implode("\n", $output)];
    }

    private function writeEnvironment(): void
    {
        $target = $this->installation();

        // Step 2 of RELEASE.md: copy the template. Nothing from this
        // repository's own .env is used.
        copy($target.'/.env.example', $target.'/.env');

        $values = [
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_KEY' => '',
            'APP_URL' => 'https://handover.example',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => (string) config("database.connections.{$this->connection}.host"),
            'DB_PORT' => (string) config("database.connections.{$this->connection}.port"),
            'DB_DATABASE' => self::CLEAN_DATABASE,
            'DB_USERNAME' => (string) config("database.connections.{$this->connection}.username"),
            'DB_PASSWORD' => (string) config("database.connections.{$this->connection}.password"),
            // No mail server on a clean machine, and the handover must not
            // depend on one existing.
            'MAIL_MAILER' => 'log',
            'CACHE_STORE' => 'file',
            'SESSION_DRIVER' => 'file',
            'QUEUE_CONNECTION' => 'database',
        ];

        $env = (string) file_get_contents($target.'/.env');

        foreach ($values as $key => $value) {
            $quoted = preg_match('/^[A-Za-z0-9_.\-\/:@]*$/', $value) === 1 ? $value : '"'.$value.'"';
            $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

            $env = preg_match($pattern, $env) === 1
                ? (string) preg_replace($pattern, $key.'='.$quoted, $env, 1)
                : rtrim($env, "\n")."\n".$key.'='.$quoted."\n";
        }

        file_put_contents($target.'/.env', $env);
    }

    /** @return array<int, string> */
    private function tablesInTheCleanDatabase(): array
    {
        return $this->cleanDatabase()->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
    }

    private function cleanDatabase(): \PDO
    {
        return new \PDO(
            sprintf(
                'mysql:host=%s;port=%s;dbname=%s',
                config("database.connections.{$this->connection}.host"),
                config("database.connections.{$this->connection}.port"),
                self::CLEAN_DATABASE,
            ),
            (string) config("database.connections.{$this->connection}.username"),
            (string) config("database.connections.{$this->connection}.password"),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );
    }

    private function emptyTheDatabase(): void
    {
        $pdo = new \PDO(
            sprintf(
                'mysql:host=%s;port=%s;dbname=%s',
                config("database.connections.{$this->connection}.host"),
                config("database.connections.{$this->connection}.port"),
                self::CLEAN_DATABASE,
            ),
            (string) config("database.connections.{$this->connection}.username"),
            (string) config("database.connections.{$this->connection}.password"),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        foreach ($pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN) as $table) {
            $pdo->exec('DROP TABLE IF EXISTS `'.$table.'`');
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    // -- the test ---------------------------------------------------------------------

    public function test_it_installs_on_a_clean_server_from_the_package_alone(): void
    {
        $this->installation();
        $this->emptyTheDatabase();
        $this->writeEnvironment();

        // 1. THE APPLICATION KEY. Everything encrypted afterwards depends on
        //    it, so it comes first — as RELEASE.md says.
        $key = $this->inServer('php artisan key:generate --force');
        $this->assertSame(0, $key['code'], "key:generate failed:\n".$key['output']);

        // 2. THE SCHEMA, against a database that has never held Aziv AI.
        $migrate = $this->inServer('php artisan migrate --force --no-interaction');
        $this->assertSame(0, $migrate['code'], "migrate failed:\n".$migrate['output']);

        // The migrations landed in the CLEAN database and not somewhere else.
        // Asserted rather than assumed, because "somewhere else" is exactly
        // what happened the first time this suite was written: the child
        // inherited PHPUnit's own DB_DATABASE and quietly re-migrated the test
        // database, and every assertion below passed without the package ever
        // being exercised.
        $this->assertNotEmpty($this->tablesInTheCleanDatabase(),
            'The clean database is still empty — the extracted copy migrated something else, '
            .'so this test proved nothing about the package.');

        // 3. THE SEED DATA — roles, permissions, settings, themes.
        $seed = $this->inServer('php artisan db:seed --force --no-interaction');
        $this->assertSame(0, $seed['code'], "db:seed failed:\n".$seed['output']);

        // 4. THE FIRST ADMINISTRATOR. Never seeded — an account with a known
        //    password is a backdoor waiting to reach production — so it is
        //    created deliberately, exactly as the documentation instructs.
        // The password is PROMPTED, never an option — an administrator's
        // password has no business in a shell history or a process list — so
        // it is piped in, which is what the documentation tells an owner who
        // is scripting this to do. `exec()` runs `sh`, so no bash here-string.
        $admin = $this->inServer(
            'printf '.escapeshellarg("A-very-long-passphrase-9!\n")
            .' | php artisan aziv:admin:create --name="The Owner" --email=owner@handover.test',
        );
        $this->assertSame(0, $admin['code'], "aziv:admin:create failed:\n".$admin['output']);

        // 5. THE CACHES, because guide 14 and RELEASE.md both tell an owner to
        //    run this and the health check grades a server that has not.
        $optimize = $this->inServer('php artisan optimize');
        $this->assertSame(0, $optimize['code'], "optimize failed:\n".$optimize['output']);

        // 6. IT RUNS. The health check is the first thing the documentation
        //    tells an owner to open, so it is the first thing proved.
        $diagnose = $this->inServer('php artisan aziv:diagnose --json --automatic');
        $report = json_decode($diagnose['output'], true);

        $this->assertIsArray($report, "aziv:diagnose produced no report:\n".$diagnose['output']);
        $this->assertNotEmpty($report['results']);

        // 7. NOTHING FUNDAMENTAL IS BROKEN on the clean server. Mail and cron
        //    are expected to be unconfigured — an owner has not done those
        //    yet — but the database, the filesystem and the application's own
        //    state must all be sound, or the package is not installable.
        $byKey = collect($report['results'])->keyBy('key');

        foreach (['database.connection', 'filesystem.writable', 'application.state'] as $key) {
            $this->assertSame('green', $byKey[$key]['status'] ?? 'missing',
                $key.' is not healthy on a clean install: '.($byKey[$key]['technical_reason'] ?? 'no result'));
        }
    }

    public function test_the_installed_copy_has_an_administrator_and_no_open_installer(): void
    {
        $this->test_it_installs_on_a_clean_server_from_the_package_alone();

        $pdo = new \PDO(
            sprintf(
                'mysql:host=%s;port=%s;dbname=%s',
                config("database.connections.{$this->connection}.host"),
                config("database.connections.{$this->connection}.port"),
                self::CLEAN_DATABASE,
            ),
            (string) config("database.connections.{$this->connection}.username"),
            (string) config("database.connections.{$this->connection}.password"),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );

        $owner = $pdo->query("SELECT email FROM users WHERE email = 'owner@handover.test'")->fetchColumn();
        $this->assertSame('owner@handover.test', $owner);

        // An account exists, so the installer is shut — the condition an
        // attacker cannot arrange. Asserted from the extracted copy's own
        // point of view rather than this repository's.
        $state = $this->inServer(
            'php artisan tinker --execute='.escapeshellarg('echo app(App\\Support\\Installer::class)->isOpen() ? "OPEN" : "CLOSED";'),
        );

        $this->assertStringContainsString('CLOSED', $state['output']);
    }

    public function test_the_documentation_an_owner_needs_came_with_it(): void
    {
        $target = $this->installation();

        // "…from the ZIP + SQL + documentation alone." No undocumented steps
        // means the steps have to be IN there.
        foreach ([
            'RELEASE.md',
            'docs/00-README.md',
            'docs/19-backup-and-restore.md',
            'docs/guides/01-server-requirements.md',
            'docs/guides/02-installation.md',
            'docs/guides/06-queue-and-cron.md',
            'docs/guides/07-mail-and-smtp.md',
            'docs/guides/18-security-checklist.md',
            'docs/guides/20-commands.md',
        ] as $document) {
            $this->assertFileExists($target.'/'.$document, $document.' did not ship with the package.');
        }
    }
}
