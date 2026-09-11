<?php

namespace Tests\Feature\Admin;

use App\Domains\Diagnostics\Support\CheckResult;
use App\Domains\Security\Services\PermissionRegistry;
use App\Filament\Pages\SystemHealth;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SystemHealthPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user->fresh();
    }

    public function test_a_super_admin_can_open_system_health(): void
    {
        $this->actingAs($this->userWithRole(PermissionRegistry::SUPER_ADMIN))
            ->get('/admin/system-health')
            ->assertOk()
            ->assertSee('System Health');
    }

    public function test_a_support_manager_can_view_but_the_page_is_still_permission_gated(): void
    {
        // Support holds diagnostics.view but not diagnostics.run.
        $this->actingAs($this->userWithRole(PermissionRegistry::SUPPORT_MANAGER))
            ->get('/admin/system-health')
            ->assertOk();
    }

    public function test_a_content_manager_is_denied_system_health(): void
    {
        // Content Manager holds no diagnostics permission at all.
        $response = $this->actingAs($this->userWithRole(PermissionRegistry::CONTENT_MANAGER))
            ->get('/admin/system-health');

        $this->assertContains($response->getStatusCode(), [403, 404]);
    }

    public function test_a_customer_cannot_reach_the_admin_panel_at_all(): void
    {
        $response = $this->actingAs($this->userWithRole(PermissionRegistry::CUSTOMER))
            ->get('/admin/system-health');

        $this->assertNotSame(200, $response->getStatusCode());
    }

    public function test_an_anonymous_visitor_is_redirected_to_sign_in(): void
    {
        $this->get('/admin/system-health')->assertRedirect();
    }

    /**
     * The rendered screen is designed to be forwarded to a hosting provider,
     * so no credential may appear anywhere in its HTML.
     */
    public function test_the_rendered_page_contains_no_credential(): void
    {
        config(['database.connections.mysql.password' => 'aziv_dev_pw']);

        $html = $this->actingAs($this->userWithRole(PermissionRegistry::SUPER_ADMIN))
            ->get('/admin/system-health')
            ->getContent();

        foreach ([config('app.key'), 'aziv_dev_pw'] as $secret) {
            if ($secret) {
                $this->assertStringNotContainsString($secret, $html,
                    'A credential appeared in the System Health page.');
            }
        }
    }

    /**
     * REGRESSION. Caching CheckResult objects serialises them, and a
     * serialised object outlives the class definition that wrote it: on a
     * store that cannot resolve the class at unserialize time it comes back as
     * __PHP_Incomplete_Class and this page dies with a fatal type error.
     *
     * PHPUnit runs the array cache driver, which stores by reference and never
     * serialises, so an ordinary "the page loads" assertion cannot see this.
     * Asserting the cached payload is plain data can, on any driver.
     */
    public function test_diagnostics_are_cached_as_plain_data_not_as_objects(): void
    {
        $this->actingAs($this->userWithRole(PermissionRegistry::SUPER_ADMIN))
            ->get('/admin/system-health')
            ->assertOk();

        $payload = \Illuminate\Support\Facades\Cache::get(SystemHealth::CACHE_KEY);

        $this->assertIsArray($payload);
        $this->assertNotEmpty($payload['results']);

        foreach ($payload['results'] as $result) {
            $this->assertIsArray($result, 'A CheckResult object reached the cache.');
        }

        // The round trip a real cache store performs must be lossless.
        $this->assertEquals($payload, unserialize(serialize($payload)));

        // And the array form must rebuild into the real thing.
        $rebuilt = CheckResult::fromArray($payload['results'][0]);
        $this->assertSame($payload['results'][0]['key'], $rebuilt->key);
        $this->assertSame($payload['results'][0]['status'], $rebuilt->status->value);
    }
}
