<?php

namespace App\Domains\Diagnostics\Checks;

use App\Domains\Diagnostics\Support\Category;
use App\Domains\Diagnostics\Support\CheckResult;
use App\Domains\Diagnostics\Support\Responsibility;
use App\Domains\Diagnostics\Support\Severity;
use App\Domains\Diagnostics\Support\Status;
use App\Support\Installer;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * What of this installation is reachable from the internet? (Owner Addendum G,
 * Phase 9.)
 *
 * THE SINGLE MOST DAMAGING DEPLOYMENT MISTAKE AVAILABLE is getting the
 * document root wrong on cPanel. Point it at the project directory instead of
 * `public/` and `.env` becomes a URL: every provider key, the database
 * password and `APP_KEY` itself, served as plain text to anybody who asks.
 * The site works perfectly the whole time.
 *
 * SO IT ASKS OVER HTTP, from this server, as a stranger would — the only way
 * to know is to try. A test that inspected the filesystem layout would prove
 * the files are where we put them and nothing about what the web server does
 * with them.
 *
 * IT ALSO CHECKS THE INSTALLER IS SHUT. A live installer can rewrite `.env`
 * and create an administrator, which is a full compromise in two clicks.
 */
class ExposureCheck extends BaseCheck
{
    /**
     * Paths that must never answer. Each is a real disclosure:
     * credentials, source, dependency inventory, and version control history.
     */
    private const MUST_NOT_BE_REACHABLE = [
        '.env' => 'every credential on this server, as plain text',
        '.env.example' => 'the shape of your configuration',
        'composer.json' => 'your dependency list and versions',
        'artisan' => 'the project root is being served instead of public/',
        'storage/logs/laravel.log' => 'application logs, which can contain personal data',
        '.git/config' => 'your version control history, including anything ever committed',
    ];

    public function key(): string
    {
        return 'security.exposure';
    }

    public function title(): string
    {
        return 'What is reachable over HTTP';
    }

    public function category(): Category
    {
        return Category::Security;
    }

    /**
     * It makes real HTTP requests to this server, so it is not run on a
     * schedule — a nightly job hitting its own front door adds noise to
     * everybody's access log for no new information.
     */
    public function isSafeToRunAutomatically(): bool
    {
        return false;
    }

    public function run(): CheckResult
    {
        $base = rtrim((string) config('app.url'), '/');

        if ($base === '' || str_contains($base, 'localhost') || str_contains($base, '127.0.0.1')) {
            return $this->result(
                Status::Grey,
                Severity::Informational,
                'APP_URL is a local address, so there is nothing to reach from outside.',
                'This check is meaningful on the server customers use.',
                'Set APP_URL to your real address and run this again there.',
            );
        }

        $exposed = [];
        $unreachable = 0;

        foreach (self::MUST_NOT_BE_REACHABLE as $path => $whatItLeaks) {
            try {
                $response = Http::timeout(8)->withoutRedirecting()->get($base.'/'.$path);
            } catch (Throwable) {
                // Could not ask. Not evidence of safety, and not a finding
                // about the server either.
                $unreachable++;

                continue;
            }

            if ($response->successful() && trim($response->body()) !== '') {
                $exposed[] = '/'.$path.' ('.$whatItLeaks.')';
            }
        }

        $installerOpen = app(Installer::class)->isOpen();

        if ($exposed !== []) {
            return $this->result(
                Status::Red,
                Severity::Critical,
                count($exposed).' path(s) are served to anybody: '.implode(', ', $exposed).'.',
                'This is a full compromise. Treat every credential on this server as known and rotate all of them.',
                'Your document root is pointing at the project folder. It must point at the public/ folder inside it. '
                    .'Fix that first, then rotate every provider key, gateway credential and database password.',
                requiresHostingSupport: true,
                supportWording: 'Please set the document root for my domain to the public/ subdirectory of my application folder.',
            );
        }

        if ($installerOpen) {
            return $this->result(
                Status::Red,
                Severity::Critical,
                'The web installer is still enabled.',
                'An open installer can rewrite the configuration and create an administrator account. It is a full compromise in two clicks.',
                'Open the installer once and complete it, or delete the install lock route by setting AZIV_INSTALLER=off in .env.',
            );
        }

        if ($unreachable === count(self::MUST_NOT_BE_REACHABLE)) {
            return $this->result(
                Status::Grey,
                Severity::Informational,
                'This server could not make an HTTP request to itself, so nothing could be checked.',
                'Outbound HTTPS may be blocked, which the network check reports separately.',
                'Nothing to do here — see the outbound HTTPS finding.',
            );
        }

        return $this->result(
            Status::Green,
            Severity::Informational,
            'None of the sensitive paths answered, and the installer is closed.',
            'The document root is correct.',
            'Nothing to do.',
        );
    }

    private function result(
        Status $status,
        Severity $severity,
        string $technicalReason,
        string $recommendedAction,
        string $adminAction,
        bool $requiresHostingSupport = false,
        string $supportWording = '',
    ): CheckResult {
        return new CheckResult(
            key: $this->key(),
            title: $this->title(),
            category: $this->category(),
            status: $status,
            severity: $severity,
            responsibility: Responsibility::Hosting,
            technicalReason: $technicalReason,
            recommendedAction: $recommendedAction,
            adminAction: $adminAction,
            requiresHostingSupport: $requiresHostingSupport,
            supportWording: $supportWording,
        );
    }
}
