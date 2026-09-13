<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * What to back up, resolved against THIS server's configuration.
 *
 * A backup list in a document drifts: a phase adds a disk, nobody updates the
 * runbook, and the first person to discover the gap is restoring. This reads
 * the configuration instead, so the list cannot be out of date — and a test
 * asserts that every configured local disk appears in it, so adding one
 * without thinking about backups fails the build.
 *
 * IT PRINTS NO CREDENTIAL. The database NAME and HOST are what an operator
 * needs to write a dump command; the password is not, and is not here.
 */
class BackupManifestCommand extends Command
{
    protected $signature = 'aziv:backup:manifest {--json : Machine-readable}';

    protected $description = 'List everything a complete backup of this installation must contain';

    public function handle(): int
    {
        $manifest = $this->manifest();

        if ($this->option('json')) {
            $this->line((string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->newLine();
        $this->components->info('A complete backup of this installation is three things.');
        $this->newLine();

        $this->components->twoColumnDetail('<fg=yellow>1. Database</>', '');
        $this->components->twoColumnDetail('   connection', $manifest['database']['connection']);
        $this->components->twoColumnDetail('   name', $manifest['database']['name']);
        $this->components->twoColumnDetail('   host', $manifest['database']['host']);

        $this->newLine();
        $this->components->twoColumnDetail('<fg=yellow>2. Files</>', '');

        foreach ($manifest['paths'] as $path) {
            $this->components->twoColumnDetail('   '.$path['disk'], $path['root']);
        }

        $this->newLine();
        $this->components->twoColumnDetail('<fg=yellow>3. APP_KEY</>', 'the one line in .env');
        $this->newLine();

        // The whole reason this command exists in a product that stores other
        // people's API keys.
        $this->components->warn(
            'Every provider key, gateway credential and webhook secret in that database is '.
            'encrypted under APP_KEY. Restore the database beside a NEW key and none of them can '.
            'ever be read again. Keep the key somewhere that is not this server.'
        );

        foreach ($manifest['excluded'] as $what => $why) {
            $this->components->twoColumnDetail('<fg=gray>not needed</> '.$what, '<fg=gray>'.$why.'</>');
        }

        $this->newLine();
        $this->line('  Full procedure, including a tested restore: docs/19-backup-and-restore.md');

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    public function manifest(): array
    {
        $connection = (string) config('database.default');

        return [
            'database' => [
                'connection' => $connection,
                'name' => (string) config("database.connections.{$connection}.database"),
                'host' => (string) config("database.connections.{$connection}.host"),
            ],
            'paths' => $this->paths(),
            'secrets' => ['APP_KEY'],
            'excluded' => [
                'public/brand' => 'derived images, regenerated from the masters in private storage',
                'storage/framework' => 'caches — restoring them is worse than not',
                'bootstrap/cache' => 'caches',
                'vendor, node_modules, public/build' => 'produced by composer install and npm run build',
            ],
        ];
    }

    /**
     * Every LOCAL disk the application writes to.
     *
     * Remote disks are excluded because they are not on this server and are
     * backed up where they live — but they are still named, so nobody reads a
     * short list and concludes there is nothing else.
     *
     * @return array<int, array{disk: string, root: string, driver: string}>
     */
    private function paths(): array
    {
        $paths = [];

        foreach ((array) config('filesystems.disks') as $name => $disk) {
            $driver = (string) ($disk['driver'] ?? '');

            $paths[] = [
                'disk' => (string) $name,
                'driver' => $driver,
                'root' => $driver === 'local'
                    ? (string) ($disk['root'] ?? '')
                    : 'not on this server — backed up wherever it lives',
            ];
        }

        return $paths;
    }
}
