<?php

namespace App\Domains\Diagnostics\Checks;

use App\Domains\AI\Models\AiProviderCredential;
use App\Domains\Diagnostics\Support\Category;
use App\Domains\Diagnostics\Support\CheckResult;
use App\Domains\Diagnostics\Support\Responsibility;
use App\Domains\Diagnostics\Support\Severity;
use App\Domains\Diagnostics\Support\Status;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * Is this copy of the application in a state it can run in? (Phase 9.)
 *
 * THREE FAULTS THAT ONLY EXIST AFTER AN UPDATE, and each is silent in a
 * different way:
 *
 *   - PENDING MIGRATIONS. New code against an old schema. The symptom is a
 *     column-not-found error on one screen while everything else works, so it
 *     reads as a bug in that screen rather than as an unfinished update.
 *   - CONFIG NOT CACHED IN PRODUCTION. Not a fault, a cost: Laravel re-reads
 *     and re-parses every config file on every request.
 *   - A STALE CACHE AFTER A DEPLOY. The opposite mistake — cached config from
 *     before the update, so a changed setting has no effect and nobody can see
 *     why.
 *
 * AND THE ONE THAT IS NOT SURVIVABLE: credentials that no longer decrypt. That
 * means `APP_KEY` changed — a restore beside a freshly generated key, or a
 * key rotated without `APP_PREVIOUS_KEYS`. Every stored provider key, gateway
 * credential and webhook secret is unreadable, and no amount of retrying
 * fixes it. Finding out here beats finding out when a customer's payment
 * cannot be verified.
 */
class ApplicationStateCheck extends BaseCheck
{
    public function key(): string
    {
        return 'application.state';
    }

    public function title(): string
    {
        return 'Application state';
    }

    public function category(): Category
    {
        return Category::Application;
    }

    public function run(): CheckResult
    {
        $critical = [];
        $advisory = [];
        $facts = [];

        // --- pending migrations --------------------------------------------
        $pending = $this->pendingMigrations();

        if ($pending === null) {
            $advisory[] = 'the migration status could not be read';
        } elseif ($pending > 0) {
            $critical[] = $pending.' database migration(s) have not been run';
        } else {
            $facts[] = 'schema up to date';
        }

        // --- credentials still decrypt --------------------------------------
        $unreadable = $this->unreadableCredentials();

        if ($unreadable > 0) {
            $critical[] = $unreadable.' stored credential(s) cannot be decrypted — APP_KEY does not match the data';
        } elseif ($unreadable === 0) {
            $facts[] = 'stored credentials decrypt';
        }

        // --- config cache ----------------------------------------------------
        $cached = file_exists(base_path('bootstrap/cache/config.php'));

        if (app()->environment('production') && ! $cached) {
            $advisory[] = 'configuration is not cached, so every request re-reads every config file';
        }

        $facts[] = $cached ? 'config cached' : 'config not cached';

        if ($critical !== []) {
            return $this->result(
                Status::Red,
                Severity::Critical,
                ucfirst(implode('; ', $critical)).'.',
                'An update is unfinished, or this database was restored beside the wrong application key.',
                'Open Admin → Maintenance and run pending migrations. If credentials cannot be decrypted, '
                    .'restore the ORIGINAL APP_KEY from your backup before doing anything else — see docs/19-backup-and-restore.md.',
            );
        }

        if ($advisory !== []) {
            return $this->result(
                Status::Yellow,
                Severity::Low,
                ucfirst(implode('; ', $advisory)).'.',
                'Nothing is broken; this is performance and housekeeping.',
                'Open Admin → Maintenance and rebuild the caches.',
            );
        }

        return $this->result(
            Status::Green,
            Severity::Informational,
            ucfirst(implode(' · ', $facts)).'.',
            'This copy of the application is in a runnable state.',
            'Nothing to do.',
        );
    }

    /** @return int|null null when the status cannot be read */
    private function pendingMigrations(): ?int
    {
        try {
            Artisan::call('migrate:status', ['--pending' => true]);

            // The command prints one line per pending migration and a
            // "Nothing to migrate" style message when there are none. Counting
            // lines that look like a migration name is more robust across
            // Laravel versions than parsing its table.
            $lines = preg_split('/\R/', trim(Artisan::output())) ?: [];

            return count(array_filter($lines, fn (string $line) => (bool) preg_match('/\d{4}_\d{2}_\d{2}_\d{6}/', $line)));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * How many stored credentials no longer decrypt.
     *
     * It asks whether decryption WORKS and counts failures. NO PLAINTEXT
     * CREDENTIAL EVER ENTERS THIS LAYER: the model answers with a boolean and
     * this counts the falses, so the only thing that can come back is an
     * integer. `secret()` deliberately is not called here — the list of
     * callers of that method is pinned by a test, and a diagnostics check has
     * no business being on it.
     */
    private function unreadableCredentials(): int
    {
        $broken = 0;

        try {
            foreach (AiProviderCredential::all() as $credential) {
                if (! $credential->isReadable()) {
                    $broken++;
                }
            }
        } catch (Throwable) {
            return 0;   // no table yet, or no database: other checks own that
        }

        return $broken;
    }

    private function result(
        Status $status,
        Severity $severity,
        string $technicalReason,
        string $recommendedAction,
        string $adminAction,
    ): CheckResult {
        return new CheckResult(
            key: $this->key(),
            title: $this->title(),
            category: $this->category(),
            status: $status,
            severity: $severity,
            responsibility: Responsibility::Application,
            technicalReason: $technicalReason,
            recommendedAction: $recommendedAction,
            adminAction: $adminAction,
        );
    }
}
