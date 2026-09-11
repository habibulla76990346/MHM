<?php

namespace App\Domains\Diagnostics\Checks;

use App\Domains\Diagnostics\Support\Category;
use App\Domains\Diagnostics\Support\CheckResult;
use App\Domains\Diagnostics\Support\Responsibility;
use App\Domains\Diagnostics\Support\Severity;
use App\Domains\Diagnostics\Support\Status;
use Illuminate\Support\Facades\DB;

class DatabaseCheck extends BaseCheck
{
    public function key(): string { return 'database.connection'; }
    public function title(): string { return 'Database connection'; }
    public function category(): Category { return Category::Database; }

    public function run(): CheckResult
    {
        $start = microtime(true);

        try {
            $version = DB::selectOne('SELECT VERSION() AS v')->v ?? 'unknown';
            $latency = (microtime(true) - $start) * 1000;

            // Prove we can actually create schema, not merely connect —
            // a read-only grant passes a SELECT and then fails at migrate time.
            $canCreate = true;
            $privError = '';
            try {
                DB::statement('CREATE TEMPORARY TABLE aziv_privilege_probe (id INT)');
                DB::statement('DROP TEMPORARY TABLE aziv_privilege_probe');
            } catch (\Throwable $e) {
                $canCreate = false;
                $privError = ' Schema privilege check failed: '.class_basename($e).'.';
            }

            return new CheckResult(
                key: $this->key(),
                title: $canCreate ? $this->title() : 'Database user lacks schema privileges',
                category: $this->category(),
                status: $canCreate ? Status::Green : Status::Red,
                severity: $canCreate ? Severity::Informational : Severity::Critical,
                responsibility: Responsibility::Database,
                technicalReason: sprintf('Connected to %s in %dms.%s', $version, (int) $latency, $privError),
                recommendedAction: $canCreate ? '' : 'The database user can read but cannot create tables, so migrations will fail.',
                adminAction: $canCreate ? '' : 'Grant ALL PRIVILEGES on the Aziv AI database to its user. In cPanel: MySQL Databases → Add User To Database → All Privileges.',
                durationMs: $latency,
            );
        } catch (\Throwable $e) {
            return new CheckResult(
                key: $this->key(),
                title: 'Database connection failed',
                category: $this->category(),
                status: Status::Red,
                severity: Severity::Critical,
                responsibility: Responsibility::Database,
                technicalReason: 'Could not connect: '.class_basename($e).' — '.$e->getMessage(),
                recommendedAction: 'Aziv AI cannot reach its database. Nothing will work until this is fixed.',
                adminAction: 'Check DB_HOST, DB_DATABASE, DB_USERNAME and DB_PASSWORD in your configuration, and confirm the database exists and the user is attached to it.',
            );
        }
    }
}
