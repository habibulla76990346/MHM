<?php

namespace App\Domains\Diagnostics\Services;

use App\Domains\Diagnostics\Support\Redactor;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * The operations that would otherwise need a shell (Owner Addendum E §4).
 *
 * THE OWNER'S CONSTRAINT IS EXPLICIT: no SSH is assumed. On hosting where
 * there is no terminal, an update that adds a migration cannot be finished, a
 * cached config cannot be rebuilt, and a queue with nothing draining it cannot
 * be nudged. Each of those turns into "the site is broken and I cannot fix it".
 *
 * EVERY TASK IS A NAMED, ALLOWLISTED OPERATION. It does not take a command
 * from the caller and run it — that would be a shell with a web form in front
 * of it, which is the thing being avoided, not provided.
 *
 * WHAT IT DELIBERATELY CANNOT DO: `composer install`, `npm run build`, or
 * anything else that needs the network or a toolchain. Those are what shipping
 * `vendor/` and compiled assets in the release package exists to avoid.
 *
 * OUTPUT IS SCRUBBED. `migrate` quotes the connection it failed on, and that
 * connection string carries the database password.
 */
class MaintenanceService
{
    /**
     * @return array<string, array{label: string, description: string, equivalent: string, destructive: bool}>
     */
    public static function tasks(): array
    {
        return [
            'migrate' => [
                'label' => 'Finish an update',
                'description' => 'Runs any database changes a new version needs. Safe to run when there are none.',
                'equivalent' => 'php artisan migrate --force',
                'destructive' => false,
            ],
            'optimize' => [
                'label' => 'Rebuild the caches',
                'description' => 'Clears and rebuilds the configuration, route and view caches. Do this after changing .env.',
                'equivalent' => 'php artisan optimize:clear && php artisan optimize',
                'destructive' => false,
            ],
            'storage-link' => [
                'label' => 'Rebuild the storage link',
                'description' => 'Recreates the public link to stored files. Needed after moving the application.',
                'equivalent' => 'php artisan storage:link',
                'destructive' => false,
            ],
            'queue' => [
                'label' => 'Process waiting jobs now',
                'description' => 'Works through the queue once and stops. Use it when the scheduler is not running yet.',
                'equivalent' => 'php artisan queue:work --stop-when-empty',
                'destructive' => false,
            ],
        ];
    }

    public static function exists(string $task): bool
    {
        return array_key_exists($task, self::tasks());
    }

    /**
     * Run one named task.
     *
     * @return array{ok: bool, output: string}
     */
    public function run(string $task): array
    {
        if (! self::exists($task)) {
            // Unreachable through the screen, which offers only these — so
            // reaching it means somebody posted a task name of their own.
            return ['ok' => false, 'output' => 'Unknown task.'];
        }

        try {
            return match ($task) {
                'migrate' => $this->artisan('migrate', ['--force' => true]),
                'optimize' => $this->sequence(['optimize:clear', 'config:cache', 'route:cache', 'view:cache']),
                'storage-link' => $this->artisan('storage:link', ['--force' => true]),
                // BOUNDED, twice over. A web request cannot hold a worker open
                // for ever, and a queue that keeps filling would keep it there.
                'queue' => $this->artisan('queue:work', [
                    '--stop-when-empty' => true,
                    '--max-time' => 25,
                    '--tries' => 1,
                ]),
            };
        } catch (Throwable $e) {
            return ['ok' => false, 'output' => Redactor::scrub($e->getMessage())];
        }
    }

    /**
     * The last lines of the log, for somebody with no way to read the file.
     *
     * READ FROM THE END, not by loading the file: a log on a busy server is
     * hundreds of megabytes, and `file_get_contents` on it takes the site down
     * rather than explaining why it is down.
     *
     * SCRUBBED, because a stack trace routinely carries a connection string,
     * and this screen is reachable by a Support Manager.
     */
    public function recentLog(int $lines = 120): string
    {
        $path = storage_path('logs/laravel.log');

        if (! is_file($path)) {
            return 'No log file yet.';
        }

        $handle = @fopen($path, 'r');

        if (! $handle) {
            return 'The log file could not be read.';
        }

        $buffer = '';
        $chunk = 4096;
        $found = 0;

        fseek($handle, 0, SEEK_END);
        $position = ftell($handle);

        while ($position > 0 && $found <= $lines) {
            $read = (int) min($chunk, $position);
            $position -= $read;
            fseek($handle, $position);
            $buffer = fread($handle, $read).$buffer;
            $found = substr_count($buffer, "\n");
        }

        fclose($handle);

        $tail = array_slice(preg_split('/\R/', $buffer) ?: [], -$lines);

        return Redactor::scrub(implode("\n", $tail));
    }

    /** @return array{ok: bool, output: string} */
    private function artisan(string $command, array $parameters = []): array
    {
        $code = Artisan::call($command, $parameters);

        return [
            'ok' => $code === 0,
            'output' => Redactor::scrub(trim(Artisan::output())) ?: 'Done.',
        ];
    }

    /**
     * @param  array<int, string>  $commands
     * @return array{ok: bool, output: string}
     */
    private function sequence(array $commands): array
    {
        $output = [];
        $ok = true;

        foreach ($commands as $command) {
            $result = $this->artisan($command);
            $ok = $ok && $result['ok'];
            $output[] = $command.': '.$result['output'];
        }

        return ['ok' => $ok, 'output' => implode("\n", $output)];
    }
}
