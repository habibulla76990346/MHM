<?php

namespace Database\Seeders;

use App\Domains\Security\Services\PermissionRegistry;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Idempotent by design (docs/04 migration discipline): re-running updates
 * existing rows rather than duplicating them, so a release can safely re-seed
 * to pick up permissions added in a later phase.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        App::make(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (PermissionRegistry::allPermissions() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        foreach (PermissionRegistry::roleMatrix() as $roleName => $permissions) {
            $role = Role::findOrCreate($roleName, 'web');
            // syncPermissions, not give: a permission REMOVED from the matrix
            // must actually be revoked on re-seed, or access only ever widens.
            $role->syncPermissions($permissions);
        }

        // Super Admin holds no explicit permissions — Gate::before grants
        // everything, so a permission added later cannot lock the owner out.
        Role::findOrCreate(PermissionRegistry::SUPER_ADMIN, 'web');

        App::make(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
