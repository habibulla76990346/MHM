<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;
use ZipArchive;

/**
 * Build the release package (Owner Addendum E §2).
 *
 * WHAT GOES IN AND WHY. The owner's constraint is that no server can be
 * assumed to have SSH, Composer or Node — so the package ships `vendor/` and
 * the COMPILED assets, because those are the three commands an owner without a
 * shell cannot run. Everything else in the archive is source.
 *
 * WHAT NEVER GOES IN, and this is the part worth being careful about: `.env`,
 * the install lock, `storage/logs`, the local database, `.git`, and anything
 * under `storage/app` — which is customers' uploaded files. A release archive
 * is emailed, uploaded to a control panel, and left in a downloads folder. It
 * has to be safe to hand to a stranger, and the test suite reads the built
 * archive adversarially to prove it is.
 *
 * THE SQL EXPORT IS SCHEMA PLUS SEED DATA, never customer data. It is the
 * alternative to running migrations for somebody whose host offers phpMyAdmin
 * and nothing else — and version-stamped, because the first question when an
 * import fails is which build it came from.
 */
class ReleaseCommand extends Command
{
    protected $signature = 'aziv:release
                            {--output= : Where to write the package (default: storage/app/release)}
                            {--skip-sql : Do not generate the SQL export}';

    protected $description = 'Build the release package: source, vendor, compiled assets, SQL and documentation';

    /**
     * Directories excluded outright.
     *
     * `storage/app` is customers' files. `storage/logs` is what those
     * customers did. `.git` is every version of everything ever committed,
     * including anything committed by mistake and removed later.
     */
    private const EXCLUDED_DIRECTORIES = [
        '.github', 'node_modules', 'tests',
        'storage/app', 'storage/logs', 'storage/framework/cache',
        'storage/framework/sessions', 'storage/framework/views',
        // PHPUnit's fake disks. Not empty scratch: a run of the suite leaves
        // real bytes here — a generated image, a synthesised recording, a
        // half-finished Livewire upload — under names that look like customer
        // content because the code that wrote them is the code that writes
        // customer content. It shipped in the first package built after a
        // test run.
        'storage/framework/testing',
        'bootstrap/cache', '.phpunit.cache', 'release',
    ];

    /**
     * Directory NAMES excluded wherever they appear, at any depth.
     *
     * `.git` is the one that matters. Composer installed from source keeps a
     * full clone inside every package, and on this build machine that turned a
     * 40MB archive into three gigabytes of other projects' version history —
     * which is not only enormous but is somebody else's repository travelling
     * inside a product download.
     *
     * A package's own `tests/` and `.github/` are the same argument in
     * miniature: nothing at runtime reads them.
     */
    private const EXCLUDED_ANYWHERE = ['.git', '.github', '.circleci', '.idea', '.vscode', 'tests', 'Tests'];

    /**
     * Files excluded outright.
     *
     * `.env` is every credential on the build machine. `installed.json` would
     * make a fresh download refuse to install.
     */
    private const EXCLUDED_FILES = [
        '.env', '.env.backup', '.env.production', '.env.testing',
        'database/database.sqlite',
        'storage/installed.json',
        'phpunit.xml', 'pint.json', '.editorconfig', '.gitattributes', '.gitignore',
        'package-lock.json', 'composer.lock',
    ];

    /**
     * Present in the source tree and required in the package.
     *
     * Named explicitly so a build that silently produced an unusable archive
     * — no vendor, no compiled assets — fails here rather than on the owner's
     * server.
     */
    public const REQUIRED_IN_PACKAGE = [
        'artisan',
        'composer.json',
        '.env.example',
        'public/index.php',
        'public/build/manifest.json',
        'public/service-worker.js',
        'vendor/autoload.php',
        'app/Support/Installer.php',
        'docs/00-README.md',
        'docs/19-backup-and-restore.md',
    ];

    public function handle(): int
    {
        $version = (string) config('aziv.version');
        $output = rtrim((string) ($this->option('output') ?: storage_path('app/release')), '/');

        File::ensureDirectoryExists($output);

        $this->components->info('Building Aziv AI '.$version);

        if (! $this->assertBuilt()) {
            return self::FAILURE;
        }

        $this->warnAboutDevelopmentDependencies();

        $archive = $output.'/aziv-ai-'.$version.'.zip';
        $files = $this->collect();

        if (! $this->writeArchive($archive, $files, $version)) {
            return self::FAILURE;
        }

        $this->components->twoColumnDetail('Package', $archive);
        $this->components->twoColumnDetail('Files', number_format(count($files)));
        $this->components->twoColumnDetail('Size', $this->human((int) filesize($archive)));

        if (! $this->option('skip-sql')) {
            $schema = $this->writeSchema($output, $version);
            $this->components->twoColumnDetail('SQL (schema only)', $schema ?: 'skipped (mysqldump not available)');

            $clean = $this->writeCleanInstall($output, $version);
            $this->components->twoColumnDetail(
                'SQL (clean install)',
                $clean ?: 'skipped (the build user cannot create a scratch database)',
            );
        }

        $manifest = $this->writeManifest($output, $version, $archive, $files);
        $this->components->twoColumnDetail('Manifest', $manifest);

        $this->newLine();
        $this->components->warn(
            'The archive contains no .env, no logs, no uploaded files and no database. '
            .'Check that before sending it anywhere: RELEASE.md in the package says what to do with it.'
        );

        return self::SUCCESS;
    }

    /**
     * Refuse to build an unusable package.
     *
     * The two things an owner without a shell cannot produce are exactly the
     * two things most easily forgotten on a build machine.
     */
    private function assertBuilt(): bool
    {
        $missing = [];

        if (! is_file(base_path('public/build/manifest.json'))) {
            $missing[] = 'compiled assets — run npm run build';
        }

        if (! is_file(base_path('vendor/autoload.php'))) {
            $missing[] = 'dependencies — run composer install --no-dev --optimize-autoloader';
        }

        foreach ($missing as $problem) {
            $this->components->error('Missing '.$problem);
        }

        return $missing === [];
    }

    /**
     * A package built with development dependencies still installed is three
     * times the size and ships a test framework to a production server.
     *
     * A warning rather than a refusal, because a developer building a package
     * to LOOK at should not be blocked — but it is loud, because the owner
     * downloading it cannot tell the difference.
     */
    private function warnAboutDevelopmentDependencies(): void
    {
        if (is_dir(base_path('vendor/phpunit'))) {
            $this->components->warn(
                'Development dependencies are installed, so this package will be much larger than it needs to be '
                .'and will contain a test framework. Build a real release with: '
                .'composer install --no-dev --optimize-autoloader'
            );
        }
    }

    /** @return array<int, string> repository-relative paths */
    private function collect(): array
    {
        $root = base_path();

        $iterator = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
                function (SplFileInfo $file) use ($root) {
                    $relative = str_replace($root.'/', '', $file->getPathname());

                    foreach (self::EXCLUDED_DIRECTORIES as $directory) {
                        if ($relative === $directory || str_starts_with($relative, $directory.'/')) {
                            return false;
                        }
                    }

                    // At ANY depth. Composer installing from source leaves a
                    // full clone inside every package.
                    if ($file->isDir() && in_array($file->getFilename(), self::EXCLUDED_ANYWHERE, true)) {
                        return false;
                    }

                    return true;
                },
            ),
        );

        $files = [];

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $relative = str_replace($root.'/', '', $file->getPathname());

            if (in_array($relative, self::EXCLUDED_FILES, true)) {
                continue;
            }

            // Belt and braces: an env file anywhere in the tree is a
            // credential — with the single exception of `.env.example`, which
            // is the opposite, and is the documentation an owner copies.
            // The project's OWN template ships and nothing else does. A
            // vendor package's fixture `.env.example` is dead weight that
            // looks exactly like a credential to anybody auditing the archive.
            if (preg_match('/(^|\/)\.env($|\.)/', $relative) === 1 && $relative !== '.env.example') {
                continue;
            }

            // A database file. A development SQLite left in the tree is
            // somebody's data, and it is exactly the kind of thing nobody
            // notices until it is in a download.
            if (preg_match('/\.(sqlite|sqlite3|db)$/i', $relative) === 1) {
                continue;
            }

            $files[] = $relative;
        }

        sort($files);

        return $files;
    }

    /** @param array<int, string> $files */
    private function writeArchive(string $path, array $files, string $version): bool
    {
        @unlink($path);

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $this->components->error('The archive could not be created at '.$path);

            return false;
        }

        foreach ($files as $relative) {
            $zip->addFile(base_path($relative), $relative);
        }

        // The directories a fresh install needs to exist and write into.
        // Empty directories do not survive a zip unless they are added.
        foreach ([
            'storage/app/private', 'storage/app/public', 'storage/framework/cache',
            'storage/framework/sessions', 'storage/framework/views', 'storage/logs',
            'bootstrap/cache',
        ] as $directory) {
            $zip->addEmptyDir($directory);
            $zip->addFromString($directory.'/.gitignore', "*\n!.gitignore\n");
        }

        $zip->addFromString('RELEASE.md', $this->releaseNotes($version));
        $zip->close();

        return true;
    }

    /**
     * Schema and seed data, never customer data.
     *
     * `--no-data` for everything, then the seed tables on their own. An export
     * carrying a live `users` table would be a data breach in an archive
     * somebody emails.
     */
    private function writeSchema(string $output, string $version): ?string
    {
        $connection = (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database");
        $path = $output.'/aziv-ai-'.$version.'-schema-only.sql';

        $defaults = $this->clientDefaults($connection);

        try {
            $status = 0;
            $command = sprintf(
                'mysqldump --defaults-extra-file=%s --no-data --skip-comments --skip-add-locks %s > %s 2>/dev/null',
                escapeshellarg($defaults),
                escapeshellarg($database),
                escapeshellarg($path),
            );

            exec($command, $ignored, $status);

            if ($status !== 0 || ! is_file($path)) {
                return null;
            }

            // Stamped, because the first question when an import fails is
            // which build it came from.
            file_put_contents(
                $path,
                "-- Aziv AI {$version} — schema only.\n"
                .'-- Generated '.now()->toDayDateTimeString().".\n"
                ."-- Contains NO customer data. Import this, then run the seeders\n"
                ."-- or open /install to finish setting up.\n\n"
                .(string) file_get_contents($path),
            );

            return $path;
        } catch (Throwable) {
            return null;
        } finally {
            @unlink($defaults);
        }
    }

    /**
     * Tables whose STRUCTURE ships and whose CONTENTS never do.
     *
     * Everything here describes a moment on the build machine rather than
     * anything about the product: a warm cache, a queue, a signed-in session.
     */
    private const VOLATILE_TABLES = [
        'cache', 'cache_locks',
        'sessions',
        'jobs', 'job_batches', 'failed_jobs',
    ];

    /**
     * `clean-install.sql` — schema AND reference data, ready for phpMyAdmin (DL-5).
     *
     * BUILT FROM A SCRATCH DATABASE, never from this one. Exporting the build
     * machine's own rows would carry whatever is in it: a developer's account,
     * a fixture gateway, an encrypted provider credential. A scratch database
     * that has been migrated and seeded and nothing else CANNOT contain any of
     * that — the guarantee is structural rather than a list of tables somebody
     * has to keep correct as the schema grows.
     *
     * It is the alternative to running migrations for an owner whose host
     * offers phpMyAdmin and nothing else. Where the build user may not create
     * a database, this is skipped and said so: the web installer and
     * `php artisan migrate` both still work, so a missing SQL file costs
     * convenience and not capability.
     */
    private function writeCleanInstall(string $output, string $version): ?string
    {
        $connection = (string) config('database.default');
        $scratch = 'aziv_release_'.substr(hash('sha256', $version.microtime()), 0, 8);
        $path = $output.'/aziv-ai-'.$version.'-clean-install.sql';

        $defaults = $this->clientDefaults($connection);

        try {
            if ($this->mysql($defaults, 'CREATE DATABASE `'.$scratch.'`') !== 0) {
                return null;
            }

            // THE CHILD GETS AN EMPTY ENVIRONMENT plus the scratch database,
            // and that is what makes this export the same file wherever it is
            // built. A child process inherits whatever the caller had —
            // PHPUnit exports CACHE_STORE and DB_DATABASE, a CI runner exports
            // something else again — and Laravel reads the environment AHEAD
            // of `.env`. Built the obvious way, the same command produced a
            // different export depending on who ran it, and the one built
            // under a test runner quietly contained no cache rows at all,
            // which made the assertion that there are none prove nothing.
            $artisan = 'env -i '
                .'PATH='.escapeshellarg((string) getenv('PATH')).' '
                .'HOME='.escapeshellarg((string) (getenv('HOME') ?: '/root')).' '
                .'DB_DATABASE='.escapeshellarg($scratch).' '
                .'php '.escapeshellarg(base_path('artisan'));

            foreach (['migrate --force --no-interaction', 'db:seed --force --no-interaction'] as $step) {
                $status = 0;
                exec($artisan.' '.$step.' > /dev/null 2>&1', $ignored, $status);

                if ($status !== 0) {
                    return null;
                }
            }

            // STRUCTURE FOR EVERY TABLE, DATA FOR EVERY TABLE BUT THE VOLATILE
            // ONES. Seeding writes cache entries — among them Spatie's
            // permission cache — and a fresh installation that imported one
            // would start life holding another database's answer to "which
            // roles exist". Queues and sessions are the same argument: they
            // describe a moment, not a product.
            $ignore = '';

            foreach (self::VOLATILE_TABLES as $table) {
                $ignore .= ' --ignore-table='.escapeshellarg($scratch.'.'.$table);
            }

            $status = 0;
            exec(sprintf(
                'mysqldump --defaults-extra-file=%s --skip-comments --skip-add-locks --no-data %s > %s 2>/dev/null',
                escapeshellarg($defaults),
                escapeshellarg($scratch),
                escapeshellarg($path),
            ), $ignored, $status);

            if ($status !== 0 || ! is_file($path)) {
                return null;
            }

            exec(sprintf(
                'mysqldump --defaults-extra-file=%s --skip-comments --skip-add-locks --no-create-info --complete-insert%s %s >> %s 2>/dev/null',
                escapeshellarg($defaults),
                $ignore,
                escapeshellarg($scratch),
                escapeshellarg($path),
            ), $ignored, $status);

            if ($status !== 0) {
                return null;
            }

            file_put_contents(
                $path,
                "-- Aziv AI {$version} — schema and reference data.\n"
                .'-- Generated '.now()->toDayDateTimeString()." from a scratch database that was\n"
                ."-- migrated and seeded and used for nothing else, so it holds NO account, NO\n"
                ."-- credential and NO customer data of any kind.\n"
                ."--\n"
                ."-- Import this into an empty database, write .env, then create your first\n"
                ."-- administrator: php artisan aziv:admin:create — or open /install.\n\n"
                .(string) file_get_contents($path),
            );

            return $path;
        } catch (Throwable) {
            return null;
        } finally {
            $this->mysql($defaults, 'DROP DATABASE IF EXISTS `'.$scratch.'`');
            @unlink($defaults);
        }
    }

    /** A 0600 client file, so no password reaches the command line or `ps`. */
    private function clientDefaults(string $connection): string
    {
        $defaults = (string) tempnam(sys_get_temp_dir(), 'azivrel');

        file_put_contents($defaults, implode("\n", [
            '[client]',
            'host='.config("database.connections.{$connection}.host"),
            'port='.config("database.connections.{$connection}.port"),
            'user='.config("database.connections.{$connection}.username"),
            'password='.config("database.connections.{$connection}.password"),
            '',
        ]));
        chmod($defaults, 0600);

        return $defaults;
    }

    private function mysql(string $defaults, string $statement): int
    {
        $status = 0;
        exec(sprintf(
            'mysql --defaults-extra-file=%s -e %s > /dev/null 2>&1',
            escapeshellarg($defaults),
            escapeshellarg($statement),
        ), $ignored, $status);

        return $status;
    }

    /** @param array<int, string> $files */
    private function writeManifest(string $output, string $version, string $archive, array $files): string
    {
        $path = $output.'/aziv-ai-'.$version.'-manifest.json';

        file_put_contents($path, json_encode([
            'product' => 'Aziv AI',
            'version' => $version,
            'built_at' => now()->toIso8601String(),
            'php' => PHP_VERSION,
            'archive' => basename($archive),
            // So an owner can prove the download was not tampered with.
            'sha256' => hash_file('sha256', $archive),
            'bytes' => filesize($archive),
            'files' => count($files),
            'excludes' => [
                'reason' => 'A release archive is emailed and left in downloads folders. None of this may travel with it.',
                'directories' => self::EXCLUDED_DIRECTORIES,
                'files' => self::EXCLUDED_FILES,
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        return $path;
    }

    private function releaseNotes(string $version): string
    {
        return <<<MD
        # Aziv AI {$version}

        ## What is in this archive

        Everything needed to run Aziv AI on a server with no shell access: the source,
        the PHP dependencies (`vendor/`) and the compiled front-end assets
        (`public/build/`). You do not need Composer or Node to install it.

        ## What is NOT in it, deliberately

        - `.env` — you create one from `.env.example`
        - Any database, any uploaded file, any log
        - Version control history

        ## Installing

        1. Upload and extract so that your domain's document root points at the
           `public/` folder inside this archive — **not** at the folder itself.
        2. Copy `.env.example` to `.env`.
        3. Create an empty database and a user for it in your control panel.
        4. Open `https://your-domain/install` and follow the six steps.
        5. Add the cron job it shows you at the end. Nothing time-based works without it.

        With shell access, the documented route is faster:

        ```
        cp .env.example .env
        php artisan key:generate
        php artisan migrate --force
        php artisan aziv:admin:create
        ```

        ## Immediately after installing

        - **Back up `APP_KEY`** from `.env`, somewhere that is not this server. Every
          provider key and payment credential is encrypted with it, and a database
          restored beside a different key can never be read.
        - Configure email, then run `php artisan aziv:mail:test you@yourdomain.com`.
        - Open Admin → System Health and work through anything red.

        Full documentation is in `docs/`, starting at `docs/00-README.md`.
        Backup and restore: `docs/19-backup-and-restore.md`.
        MD;
    }

    private function human(int $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024 || $unit === 'GB') {
                return round($bytes, $unit === 'B' ? 0 : 1).' '.$unit;
            }

            $bytes = (int) ($bytes / 1024);
        }

        return $bytes.' B';
    }
}
