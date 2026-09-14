<?php

namespace Tests\Feature;

use Tests\TestCase;

class SmokeTest extends TestCase
{
    public function test_the_home_page_loads(): void
    {
        $this->get('/')->assertOk()->assertSee(config('app.name'));
    }

    public function test_the_health_endpoint_responds(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_the_admin_panel_redirects_anonymous_visitors_to_login(): void
    {
        $this->get('/admin')->assertRedirect();
    }

    /**
     * The compiled stylesheet is a function of TRACKED FILES ONLY.
     *
     * `storage/framework/views` is a gitignored, machine-local cache that
     * `optimize:clear` empties. Listing it as a Tailwind source made two
     * builds of the same commit differ by 16 KB — a warm cache full of
     * Filament's own compiled views quietly put admin-panel classes in the
     * customer stylesheet, and a cleared one did not. The release package
     * ships the compiled assets, and a handover builds from a fresh clone,
     * so "which pages did somebody happen to open before building?" cannot
     * be an input.
     */
    public function test_the_stylesheet_build_cannot_depend_on_a_machine_local_cache(): void
    {
        foreach (['app.css', 'filament/admin/theme.css'] as $stylesheet) {
            $source = (string) file_get_contents(resource_path('css/'.$stylesheet));

            preg_match_all("/@source\s+'([^']+)'/", $source, $matches);

            foreach ($matches[1] as $path) {
                $this->assertStringNotContainsString('storage/', $path,
                    $stylesheet.' reads '.$path.', which is gitignored and wiped by optimize:clear — '
                    .'so the same commit compiles a different stylesheet depending on the machine.');
                $this->assertStringNotContainsString('bootstrap/cache', $path,
                    $stylesheet.' reads a build cache, so its output is not reproducible.');
            }
        }
    }

    /**
     * Guards the rule from docs/08-theme-branding-system.md §9: every colour
     * must reference a design token, so the theme system can actually re-skin
     * the application. A hard-coded colour silently stops responding to theme
     * changes — invisible in development, obvious to a customer.
     */
    public function test_no_blade_template_hard_codes_a_colour(): void
    {
        $offenders = [];
        $pattern = '/(?:bg|text|border|ring|from|to|via)-(?:red|blue|green|yellow|purple|pink|indigo|gray|grey|slate|zinc|neutral|stone|orange|amber|lime|emerald|teal|cyan|sky|violet|fuchsia|rose)-\d{2,3}|#[0-9a-fA-F]{3,8}\b/';

        // RecursiveDirectoryIterator, not glob('**'): PHP's glob does not
        // recurse, so '**' matches exactly one directory level. Every template
        // nested deeper than that — which is most of them — went unchecked, and
        // a gate that cannot fail is not a gate.
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            // ONE exemption, and it is structural rather than a concession.
            // The offline page is served from the service worker cache with no
            // server behind it, so it can reference nothing that has to be
            // generated — not the theme, not a compiled stylesheet whose hash
            // changes every release. Its colours are literal because there is
            // nothing available to resolve a token against at the moment it is
            // shown.
            if ($file->getFilename() === 'offline.blade.php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            if (preg_match_all($pattern, $contents, $m)) {
                $offenders[str_replace(base_path().'/', '', $file->getPathname())] = array_unique($m[0]);
            }
        }

        $this->assertSame([], $offenders,
            'Hard-coded colours found. Use design tokens (bg-surface, text-muted, var(--color-*)) instead: '
            .json_encode($offenders));
    }
}
