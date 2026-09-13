<?php

namespace App\Domains\Diagnostics\Checks;

use App\Domains\Diagnostics\Support\Category;
use App\Domains\Diagnostics\Support\CheckResult;
use App\Domains\Diagnostics\Support\Responsibility;
use App\Domains\Diagnostics\Support\Severity;
use App\Domains\Diagnostics\Support\Status;

/**
 * The settings that are only wrong once you are live.
 *
 * EVERY ONE OF THESE IS INVISIBLE UNTIL IT COSTS SOMETHING. Debug mode prints
 * the environment file to whoever triggers an error. A session cookie without
 * the secure flag travels over plain HTTP, where anyone on the path can lift
 * it and become that customer. An `APP_URL` that says `http` mints every
 * emailed link with the wrong scheme. None of them break a page, so nothing
 * complains — which is exactly why they belong on a screen that complains.
 *
 * IT NAMES NO VALUE. Each finding says which setting is wrong and what it
 * should be; none of them prints what it currently contains, because this
 * report is designed to be forwarded to a hosting provider (Rule 4).
 *
 * GRADED BY ENVIRONMENT. Outside production every one of these is normal — a
 * developer's machine is http, with debug on, and marking that red would teach
 * people that this screen is noise.
 */
class ProductionSecurityCheck extends BaseCheck
{
    public function key(): string
    {
        return 'security.production';
    }

    public function title(): string
    {
        return 'Production hardening';
    }

    public function category(): Category
    {
        return Category::Security;
    }

    public function run(): CheckResult
    {
        $live = app()->environment('production');
        $appUrl = (string) config('app.url');
        $servedOverTls = str_starts_with(strtolower($appUrl), 'https://');

        /** @var array<int, string> $critical */
        $critical = [];
        /** @var array<int, string> $advisory */
        $advisory = [];

        if (blank(config('app.key'))) {
            // Not survivable: nothing encrypted can be read, and nothing new
            // can be encrypted either.
            $critical[] = 'APP_KEY is not set — run php artisan key:generate';
        }

        if (config('app.debug')) {
            $critical[] = 'APP_DEBUG is on — an error page would print this server\'s configuration to a visitor';
        }

        if (! $servedOverTls) {
            $critical[] = 'APP_URL is not https — every emailed link is signed with the wrong scheme and will be refused';
        }

        // The cookie flag only matters where there is TLS to protect.
        if ($servedOverTls && ! config('session.secure')) {
            $critical[] = 'SESSION_SECURE_COOKIE is off — the session cookie is sent over plain HTTP too, where it can be stolen';
        }

        if (! config('session.http_only')) {
            $critical[] = 'SESSION_HTTP_ONLY is off — a script on the page can read the session cookie';
        }

        if (config('session.same_site') === null || config('session.same_site') === 'none') {
            $advisory[] = 'SESSION_SAME_SITE should be "lax": it survives the return from a payment gateway and blocks cross-site posts';
        }

        if ($servedOverTls && (int) config('aziv.security.hsts_max_age') === 0) {
            $advisory[] = 'HSTS is switched off — a returning visitor can still be pushed onto plain HTTP once';
        }

        if (! config('session.encrypt')) {
            $advisory[] = 'SESSION_ENCRYPT is off — session contents are readable by anything that can read the session store';
        }

        if (! $live) {
            return $this->result(
                Status::Grey,
                Severity::Informational,
                'Not a production environment, so these are not graded.',
                'A developer machine is plain HTTP with debug on, and that is correct.',
                'Set APP_ENV=production on the live server and run this again.',
            );
        }

        if ($critical !== []) {
            return $this->result(
                Status::Red,
                Severity::Critical,
                count($critical).' setting(s) are unsafe on a live server: '.implode('; ', $critical).'.',
                'Each one is exploitable by somebody who has done nothing more than visit the site.',
                'Correct these in .env, then run php artisan config:clear.',
            );
        }

        if ($advisory !== []) {
            return $this->result(
                Status::Yellow,
                Severity::Medium,
                implode('; ', $advisory).'.',
                'Nothing here is exploitable on its own; each one removes a step from an attack.',
                'Set these in .env when you are ready, then run php artisan config:clear.',
            );
        }

        return $this->result(
            Status::Green,
            Severity::Informational,
            'Debug is off, the key is set, the site is https and the session cookie is secure.',
            'Nothing further to harden here.',
            'Nothing to do.',
        );
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
            responsibility: Responsibility::Configuration,
            technicalReason: $technicalReason,
            recommendedAction: $recommendedAction,
            adminAction: $adminAction,
        );
    }
}
