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
     * Guards the rule from docs/08-theme-branding-system.md §9: every colour
     * must reference a design token, so the theme system can actually re-skin
     * the application. A hard-coded colour silently stops responding to theme
     * changes — invisible in development, obvious to a customer.
     */
    public function test_no_blade_template_hard_codes_a_colour(): void
    {
        $offenders = [];
        $pattern = '/(?:bg|text|border|ring|from|to|via)-(?:red|blue|green|yellow|purple|pink|indigo|gray|grey|slate|zinc|neutral|stone|orange|amber|lime|emerald|teal|cyan|sky|violet|fuchsia|rose)-\d{2,3}|#[0-9a-fA-F]{3,8}\b/';

        foreach (glob(resource_path('views').'/**/*.blade.php') + glob(resource_path('views').'/*.blade.php') as $file) {
            $contents = file_get_contents($file);
            if (preg_match_all($pattern, $contents, $m)) {
                $offenders[str_replace(base_path().'/', '', $file)] = array_unique($m[0]);
            }
        }

        $this->assertSame([], $offenders,
            'Hard-coded colours found. Use design tokens (bg-surface, text-muted, var(--color-*)) instead: '
            .json_encode($offenders));
    }
}
