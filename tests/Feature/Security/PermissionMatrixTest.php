<?php

namespace Tests\Feature\Security;

use App\Domains\Security\Services\PermissionRegistry;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PermissionMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->assignRole($role);

        return $user->fresh();
    }

    /**
     * Blueprint §9: "A support role must not automatically gain access to API
     * credentials or financial configuration."
     *
     * This is the single most important row in the matrix — a support agent
     * with provider credentials can drain the owner's AI budget, and one with
     * billing configuration can change what customers are charged.
     */
    #[DataProvider('forbiddenForSupport')]
    public function test_support_manager_is_denied_credentials_and_financial_configuration(string $permission): void
    {
        $support = $this->userWithRole(PermissionRegistry::SUPPORT_MANAGER);

        $this->assertFalse(
            $support->can($permission),
            "Support Manager must not hold [{$permission}] — blueprint §9 forbids it."
        );
    }

    public static function forbiddenForSupport(): array
    {
        return [
            ['credentials.view'],
            ['credentials.manage'],
            ['billing.manage'],
            ['billing.refund'],
            ['billing.gateways.manage'],
            ['billing.gateways.credentials'],
            ['billing.tax.manage'],
            ['settings.system.update'],
            ['security.roles.manage'],
            ['users.impersonate'],
            ['themes.custom_css'],
        ];
    }

    public function test_content_manager_is_denied_credentials_and_billing(): void
    {
        $content = $this->userWithRole(PermissionRegistry::CONTENT_MANAGER);

        foreach (['credentials.view', 'credentials.manage', 'billing.manage', 'users.view'] as $permission) {
            $this->assertFalse($content->can($permission),
                "Content Manager must not hold [{$permission}].");
        }
    }

    public function test_finance_manager_is_denied_provider_credentials(): void
    {
        $finance = $this->userWithRole(PermissionRegistry::FINANCE_MANAGER);

        // Finance handles money, not AI provider keys.
        $this->assertFalse($finance->can('credentials.manage'));
        $this->assertFalse($finance->can('providers.manage'));
        // But it does hold the billing permissions its job needs.
        $this->assertTrue($finance->can('billing.manage'));
        $this->assertTrue($finance->can('billing.refund'));
    }

    public function test_super_admin_holds_every_permission_without_being_granted_any(): void
    {
        $superAdmin = $this->userWithRole(PermissionRegistry::SUPER_ADMIN);

        // Deliberately holds no explicit permissions — Gate::before grants all,
        // so a permission added in a later phase cannot lock the owner out.
        $this->assertCount(0, $superAdmin->getAllPermissions());

        foreach (PermissionRegistry::allPermissions() as $permission) {
            $this->assertTrue($superAdmin->can($permission),
                "Super Admin should hold [{$permission}] implicitly.");
        }
    }

    /**
     * Deny by default: a permission introduced later must be unavailable to
     * every role until it is explicitly granted.
     */
    public function test_an_unknown_permission_is_denied_to_every_role_except_super_admin(): void
    {
        foreach ([
            PermissionRegistry::ADMIN,
            PermissionRegistry::SUPPORT_MANAGER,
            PermissionRegistry::FINANCE_MANAGER,
            PermissionRegistry::CONTENT_MANAGER,
            PermissionRegistry::CUSTOMER,
        ] as $role) {
            $this->assertFalse(
                $this->userWithRole($role)->can('some.permission.added.in.phase.7'),
                "[{$role}] must be denied a permission that has not been granted."
            );
        }
    }

    /**
     * Defence in depth: even if a custom role is granted a Super-Admin-only
     * permission, Gate::after revokes it.
     */
    public function test_super_admin_only_permissions_cannot_be_granted_to_another_role(): void
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $role = \Spatie\Permission\Models\Role::findOrCreate('Rogue Role', 'web');
        $role->syncPermissions(PermissionRegistry::superAdminOnly());
        $user->assignRole($role);

        foreach (PermissionRegistry::superAdminOnly() as $permission) {
            $this->assertFalse($user->fresh()->can($permission),
                "[{$permission}] must never take effect for a non-Super-Admin, even if granted.");
        }
    }

    public function test_customers_hold_no_admin_permissions(): void
    {
        $customer = $this->userWithRole(PermissionRegistry::CUSTOMER);

        foreach (PermissionRegistry::allPermissions() as $permission) {
            $this->assertFalse($customer->can($permission));
        }
    }

    public function test_a_suspended_admin_cannot_reach_the_admin_panel(): void
    {
        $admin = $this->userWithRole(PermissionRegistry::ADMIN);
        $admin->update(['status' => User::STATUS_SUSPENDED]);

        $panel = \Filament\Facades\Filament::getPanel('admin');

        $this->assertFalse($admin->fresh()->canAccessPanel($panel),
            'A suspended account must be refused the Admin Panel regardless of its roles.');
    }

    public function test_a_customer_cannot_reach_the_admin_panel(): void
    {
        $customer = $this->userWithRole(PermissionRegistry::CUSTOMER);
        $panel = \Filament\Facades\Filament::getPanel('admin');

        $this->assertFalse($customer->canAccessPanel($panel));
    }
}
