<?php

namespace Tests\Feature\Deployment;

use App\Domains\Diagnostics\Checks\ProductionSecurityCheck;
use App\Domains\Diagnostics\Support\Status;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The settings that are only wrong once the site is live.
 *
 * NONE OF THESE BREAK A PAGE. Debug mode renders beautifully, a session cookie
 * without the secure flag works perfectly, and a missing `nosniff` header is
 * invisible in every browser. That is precisely the problem: a fault that
 * nothing complains about is a fault nobody finds, so each one is asserted
 * here and graded on the System Health screen.
 *
 * THE PRODUCTION CHECKS ARE SIMULATED, not skipped. The previous version of
 * the debug assertion skipped itself in `local` AND `testing` — which is every
 * environment a test ever runs in, so it had never once executed. A gate that
 * cannot fail is decorative.
 */
class ProductionHardeningTest extends TestCase
{
    use RefreshDatabase;

    /** Run a closure as though this were a live server. */
    private function asProduction(callable $body): void
    {
        $previous = app()->environment();

        app()->detectEnvironment(fn () => 'production');

        try {
            $body();
        } finally {
            app()->detectEnvironment(fn () => $previous);
        }
    }

    // -- headers on every response --------------------------------------------

    public function test_every_response_carries_the_headers_that_close_real_attacks(): void
    {
        $response = $this->get('/');

        // A browser deciding that an uploaded file is really a script.
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        // The admin panel framed inside somebody else's page.
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        // A signed renewal link in the address bar, handed to the next site
        // the customer clicks through to.
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('X-Permitted-Cross-Domain-Policies', 'none');
    }

    public function test_the_headers_are_global_and_not_only_on_rendered_pages(): void
    {
        // A JSON error, a health probe and a webhook are responses too. This
        // one is outside the web group entirely.
        $this->get('/up')->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    // -- HSTS, which is the one with teeth ------------------------------------

    public function test_hsts_is_not_announced_over_plain_http(): void
    {
        // Sending it from a developer machine would pin `localhost` to HTTPS
        // in that developer's own browser, which is hard to undo.
        $this->get('/')->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_hsts_is_sent_over_tls_with_the_configured_lifetime(): void
    {
        config([
            'aziv.security.hsts_max_age' => 15552000,
            'aziv.security.hsts_include_subdomains' => false,
        ]);

        $this->withServerVariables(['HTTPS' => 'on'])
            ->get('https://localhost/')
            ->assertHeader('Strict-Transport-Security', 'max-age=15552000');
    }

    public function test_hsts_can_be_switched_off_while_a_certificate_is_being_sorted_out(): void
    {
        config(['aziv.security.hsts_max_age' => 0]);

        $this->withServerVariables(['HTTPS' => 'on'])
            ->get('https://localhost/')
            ->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_subdomains_are_included_only_when_asked_for(): void
    {
        config([
            'aziv.security.hsts_max_age' => 600,
            'aziv.security.hsts_include_subdomains' => true,
        ]);

        $this->withServerVariables(['HTTPS' => 'on'])
            ->get('https://localhost/')
            ->assertHeader('Strict-Transport-Security', 'max-age=600; includeSubDomains');
    }

    // -- the session cookie ---------------------------------------------------

    public function test_the_session_cookie_is_secure_by_default_in_production(): void
    {
        // Laravel ships this unset, which means a live site whose .env predates
        // the setting sends the cookie over plain HTTP as well. The DEFAULT is
        // what is under test: forgetting must not be the insecure answer.
        $keys = ['APP_ENV' => 'production', 'SESSION_SECURE_COOKIE' => null];
        $restore = [];

        foreach ($keys as $key => $value) {
            $restore[$key] = $_ENV[$key] ?? null;
            $value === null ? $this->forgetEnv($key) : ($_ENV[$key] = $_SERVER[$key] = $value);
        }

        try {
            $config = require config_path('session.php');

            $this->assertTrue($config['secure'],
                'A production server that never set SESSION_SECURE_COOKIE would send the session cookie over plain HTTP.');
        } finally {
            foreach ($restore as $key => $value) {
                $value === null ? $this->forgetEnv($key) : ($_ENV[$key] = $_SERVER[$key] = $value);
            }
        }
    }

    private function forgetEnv(string $key): void
    {
        unset($_ENV[$key], $_SERVER[$key]);
        putenv($key);
    }

    // -- graded where somebody will see it ------------------------------------

    public function test_debug_mode_on_a_live_server_is_a_critical_finding(): void
    {
        $this->asProduction(function (): void {
            config(['app.debug' => true, 'app.url' => 'https://aziv.example']);

            $result = app(ProductionSecurityCheck::class)->run();

            $this->assertSame(Status::Red, $result->status);
            $this->assertStringContainsString('APP_DEBUG', $result->technicalReason);
        });
    }

    public function test_an_insecure_session_cookie_on_an_https_site_is_a_critical_finding(): void
    {
        $this->asProduction(function (): void {
            config([
                'app.debug' => false,
                'app.url' => 'https://aziv.example',
                'session.secure' => false,
            ]);

            $result = app(ProductionSecurityCheck::class)->run();

            $this->assertSame(Status::Red, $result->status);
            $this->assertStringContainsString('SESSION_SECURE_COOKIE', $result->technicalReason);
        });
    }

    public function test_a_plain_http_app_url_is_a_critical_finding_because_every_signed_link_breaks(): void
    {
        $this->asProduction(function (): void {
            config(['app.debug' => false, 'app.url' => 'http://aziv.example']);

            $result = app(ProductionSecurityCheck::class)->run();

            $this->assertSame(Status::Red, $result->status);
            $this->assertStringContainsString('APP_URL', $result->technicalReason);
        });
    }

    public function test_a_correctly_hardened_server_reports_green(): void
    {
        $this->asProduction(function (): void {
            config([
                'app.debug' => false,
                'app.url' => 'https://aziv.example',
                'session.secure' => true,
                'session.http_only' => true,
                'session.same_site' => 'lax',
                'session.encrypt' => true,
                'aziv.security.hsts_max_age' => 15552000,
            ]);

            $this->assertSame(Status::Green, app(ProductionSecurityCheck::class)->run()->status);
        });
    }

    public function test_the_check_never_prints_the_value_of_a_setting(): void
    {
        // The report is designed to be forwarded to a hosting provider, so it
        // says WHICH setting is wrong and never WHAT it contains (Rule 4).
        $this->asProduction(function (): void {
            config([
                'app.debug' => true,
                'app.key' => 'base64:SUPERSECRETAPPLICATIONKEYVALUE0000000000000=',
                'app.url' => 'http://aziv.example',
            ]);

            $result = app(ProductionSecurityCheck::class)->run();

            $this->assertStringNotContainsString('SUPERSECRET', $result->technicalReason.$result->recommendedAction.$result->adminAction);
        });
    }
}
