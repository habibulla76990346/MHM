<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Whether this copy of Aziv AI has been installed, and whether the browser
 * installer is allowed to run (Owner Addendum E §4).
 *
 * A WEB INSTALLER IS A SERIOUS ATTACK SURFACE. Left open on a live site it can
 * rewrite `.env` and create an administrator: a full compromise in two clicks.
 * So "may it run?" is answered here, in one place, by THREE independent
 * conditions rather than by a single flag anybody could clear:
 *
 *   1. an explicit switch, so an owner who will never use it can turn it off
 *      before deploying and never think about it again;
 *   2. a LOCK FILE, written when installation completes; and
 *   3. the DATABASE ITSELF — if there are already users, this is not a fresh
 *      install however the files look.
 *
 * The third is the one that matters, because it is the only one an attacker
 * cannot arrange: deleting the lock file is easy if you already have file
 * access, and by then the installer is not your problem. It also covers the
 * ordinary case of somebody restoring a backup over a fresh checkout.
 *
 * IT EXISTS BECAUSE SSH IS NOT ASSUMED. On hosting with no shell there is no
 * `php artisan migrate` and no `key:generate`. The command line stays the
 * documented, preferred route where it exists; this is the fallback, and it
 * is designed to shut itself.
 */
class Installer
{
    /**
     * Outside `public/`, and outside anything a cache clear touches.
     *
     * Not in `bootstrap/cache/` — `optimize:clear` empties that, and an
     * installer that reopens itself after routine maintenance is worse than
     * one that was never locked.
     */
    public function lockPath(): string
    {
        return storage_path('installed.json');
    }

    /** Has installation been completed on this copy? */
    public function isInstalled(): bool
    {
        return $this->isLocked() || $this->databaseHasUsers();
    }

    public function isLocked(): bool
    {
        return is_file($this->lockPath());
    }

    /**
     * May the installer routes respond at all?
     *
     * Every condition has to allow it. The switch defaults to ON because a
     * fresh download has to be installable by somebody with no shell — and it
     * closes itself the moment installation succeeds.
     */
    public function isOpen(): bool
    {
        return $this->isEnabled() && ! $this->isInstalled();
    }

    /** The explicit switch. Anything but a truthy value closes the installer. */
    public function isEnabled(): bool
    {
        return filter_var(env('AZIV_INSTALLER', true), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Close it, permanently.
     *
     * Records WHEN and WHICH VERSION, because the first question when an
     * update goes wrong is "what was this installed from?". No credential, no
     * database name, nothing that would matter if the file were readable.
     */
    public function lock(string $version = ''): void
    {
        @file_put_contents($this->lockPath(), json_encode([
            'installed_at' => now()->toIso8601String(),
            'version' => $version !== '' ? $version : (string) config('aziv.version', 'unknown'),
            'php' => PHP_VERSION,
        ], JSON_PRETTY_PRINT).PHP_EOL);
    }

    /** @return array<string, mixed>|null */
    public function details(): ?array
    {
        if (! $this->isLocked()) {
            return null;
        }

        $decoded = json_decode((string) @file_get_contents($this->lockPath()), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Is there already an account on this database?
     *
     * The condition an attacker cannot arrange. Wrapped because on a genuinely
     * fresh install there is no database to ask — and "I could not connect"
     * must read as "not installed yet", or the installer locks itself out of
     * the very situation it exists for.
     */
    private function databaseHasUsers(): bool
    {
        try {
            return Schema::hasTable('users') && DB::table('users')->exists();
        } catch (Throwable) {
            return false;
        }
    }
}
