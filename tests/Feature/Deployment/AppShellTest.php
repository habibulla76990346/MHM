<?php

namespace Tests\Feature\Deployment;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The app shell and the offline page (Owner Addendum A, Phase 9).
 *
 * THE RISK IN A SERVICE WORKER IS NOT THAT IT FAILS. It is that it succeeds at
 * caching something it should never have touched: a conversation, an invoice, a
 * generated image. A service worker cache is origin-scoped and survives sign-out,
 * so anything private in it is readable by the next person to pick up the
 * device — a shared laptop, a phone that is sold, a library machine.
 *
 * So this suite is mostly a reading of the worker's own rules.
 */
class AppShellTest extends TestCase
{
    use RefreshDatabase;

    private function worker(): string
    {
        return (string) file_get_contents(public_path('service-worker.js'));
    }

    public function test_the_offline_page_is_a_real_route(): void
    {
        // A real response, so the worker precaches something the server
        // actually serves — and so somebody who lands here with a working
        // connection gets a page rather than a 404.
        $this->get('/offline')->assertOk()->assertSee('You are offline');
    }

    public function test_the_offline_page_depends_on_nothing_that_has_to_be_generated(): void
    {
        $source = (string) file_get_contents(resource_path('views/offline.blade.php'));

        // It is shown when the server is unreachable. A theme query, a
        // branding lookup or a Vite asset reference would each be a
        // dependency on the thing that is down.
        $this->assertStringNotContainsString('@vite', $source);
        $this->assertStringNotContainsString('settings(', $source);
        $this->assertStringNotContainsString('x-layouts', $source);
        $this->assertStringNotContainsString('route(', $source);
    }

    public function test_the_worker_exists_and_is_served_from_the_scope_root(): void
    {
        // It must be reachable at `/` to control the whole origin, and it must
        // keep working when PHP is the thing that is down — so it is a static
        // file, not a route.
        $this->assertFileExists(public_path('service-worker.js'));
    }

    public function test_nothing_private_is_ever_cached(): void
    {
        $worker = $this->worker();

        // Each of these is somebody's data, and the cache outlives their
        // session.
        foreach (['/admin', '/chat/', '/voice/', '/media/', '/files/', '/billing', '/checkout', '/renew', '/livewire/'] as $path) {
            $this->assertStringContainsString("'".$path."'", $worker,
                $path.' is not on the never-cache list.');
        }
    }

    public function test_only_get_requests_are_ever_considered(): void
    {
        // A cached POST is a payment or a message sent twice.
        $this->assertStringContainsString("request.method !== 'GET'", $this->worker());
    }

    public function test_another_origin_is_never_cached(): void
    {
        // A provider's CDN is not ours to store.
        $this->assertStringContainsString('url.origin !== self.location.origin', $this->worker());
    }

    public function test_a_navigation_goes_to_the_network_first(): void
    {
        $worker = $this->worker();

        // A cached page of a live application is worse than a slow one: it
        // shows a balance, a plan or a conversation that has since changed.
        $this->assertStringContainsString("request.mode === 'navigate'", $worker);
        $this->assertMatchesRegularExpression('/try\s*\{\s*return await fetch\(request\)/', $worker);
    }

    public function test_only_content_hashed_assets_are_cached_first(): void
    {
        $worker = $this->worker();

        // A build asset cannot go stale — a changed file has a different name.
        $this->assertStringContainsString("url.pathname.startsWith('/build/')", $worker);
        $this->assertStringContainsString("url.pathname.startsWith('/brand/')", $worker);
    }

    public function test_the_cache_is_versioned_so_a_release_cannot_leave_a_stale_shell(): void
    {
        $worker = $this->worker();

        $this->assertMatchesRegularExpression("/const VERSION = 'aziv-v\d+'/", $worker);
        // And old versions are actually removed, or the customer's storage
        // fills with shells no page will ever ask for again.
        $this->assertStringContainsString('caches.delete(name)', $worker);
    }

    public function test_it_is_registered_only_where_a_browser_will_accept_it(): void
    {
        $app = (string) file_get_contents(resource_path('js/app.js'));

        // Anywhere else the browser refuses and the console error reads like
        // a bug in the application.
        $this->assertStringContainsString("location.protocol !== 'https:'", $app);
        $this->assertStringContainsString('serviceWorker', $app);
    }
}
