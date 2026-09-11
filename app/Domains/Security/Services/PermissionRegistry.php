<?php

namespace App\Domains\Security\Services;

/**
 * Every permission in Aziv AI, and the role matrix from blueprint §9.
 *
 * DENY BY DEFAULT: a permission added in a later phase is unavailable to every
 * role until explicitly granted here. The system never silently widens access
 * as it grows.
 */
class PermissionRegistry
{
    public const SUPER_ADMIN = 'Super Admin';

    public const ADMIN = 'Admin';

    public const SUPPORT_MANAGER = 'Support Manager';

    public const FINANCE_MANAGER = 'Finance Manager';

    public const CONTENT_MANAGER = 'Content Manager';

    public const CUSTOMER = 'Customer';

    /**
     * Permissions grouped by domain, as §9 requires.
     *
     * @return array<string, array<int, string>>
     */
    public static function permissions(): array
    {
        return [
            'users' => [
                'users.view', 'users.create', 'users.update', 'users.suspend',
                'users.restrict', 'users.verify', 'users.delete', 'users.impersonate',
                'users.adjust_credits',
            ],
            'security' => [
                'security.roles.view', 'security.roles.manage',
                'security.permissions.manage', 'security.sessions.revoke',
                'security.mfa.enforce',
            ],
            'logs' => [
                'logs.view', 'logs.export',
            ],
            'settings' => [
                'settings.view', 'settings.update',
                'settings.system.update', 'settings.uploads.update',
            ],
            'diagnostics' => [
                'diagnostics.view', 'diagnostics.run', 'diagnostics.security.view',
                'diagnostics.export',
            ],
            'files' => [
                'files.view', 'files.upload', 'files.delete', 'files.download_any',
            ],
            // Declared now, granted in the phase that builds them. Deny-by-default
            // means listing a permission early costs nothing.
            'providers' => [
                'providers.view', 'providers.manage', 'providers.test',
            ],
            'credentials' => [
                'credentials.view', 'credentials.manage',
            ],
            'models' => [
                'models.view', 'models.manage', 'models.sync',
            ],
            'routing' => [
                'routing.view', 'routing.manage',
            ],
            'billing' => [
                'billing.view', 'billing.manage', 'billing.refund',
                'billing.gateways.manage', 'billing.gateways.credentials',
                'billing.tax.manage',
            ],
            'content' => [
                'content.view', 'content.manage', 'content.publish',
            ],
            'themes' => [
                'themes.view', 'themes.manage', 'themes.custom_css',
            ],
            // Separate from themes: replacing the logo writes a file into the
            // web root (owner decision D-13), which is a different kind of
            // trust from choosing a colour.
            'branding' => [
                'branding.view', 'branding.manage',
            ],
            'analytics' => [
                'analytics.view', 'analytics.costs.view', 'analytics.revenue.view',
            ],
        ];
    }

    /** @return array<int, string> */
    public static function allPermissions(): array
    {
        return array_merge(...array_values(self::permissions()));
    }

    /**
     * The §9 role matrix.
     *
     * Two rows are explicit blueprint requirements and are enforced by their
     * ABSENCE here: a Support Manager never receives `credentials.*` or
     * `billing.*` configuration, and a Content Manager never receives either.
     *
     * Super Admin is deliberately NOT listed — it is granted everything via a
     * Gate::before hook, so a permission added later cannot accidentally lock
     * the owner out of their own platform.
     *
     * @return array<string, array<int, string>>
     */
    public static function roleMatrix(): array
    {
        return [
            self::ADMIN => [
                'users.view', 'users.create', 'users.update', 'users.suspend',
                'users.restrict', 'users.verify', 'users.adjust_credits',
                'security.roles.view', 'security.sessions.revoke',
                'logs.view',
                'settings.view', 'settings.update', 'settings.uploads.update',
                'diagnostics.view', 'diagnostics.run', 'diagnostics.export',
                'files.view', 'files.upload', 'files.delete',
                'providers.view', 'providers.manage', 'providers.test',
                'credentials.view', 'credentials.manage',
                'models.view', 'models.manage', 'models.sync',
                'routing.view', 'routing.manage',
                'billing.view', 'billing.manage', 'billing.gateways.manage',
                'content.view', 'content.manage', 'content.publish',
                'themes.view', 'themes.manage',
                'branding.view', 'branding.manage',
                'analytics.view', 'analytics.costs.view', 'analytics.revenue.view',
            ],

            // §9: "A support role must not automatically gain access to API
            // credentials or financial configuration."
            self::SUPPORT_MANAGER => [
                'users.view', 'users.update', 'users.suspend', 'users.restrict', 'users.verify',
                'security.sessions.revoke',
                'logs.view',
                'settings.view',
                'diagnostics.view',
                'files.view',
                'providers.view',
                'models.view',
                'billing.view',          // read-only: see a customer's plan
                'analytics.view',
            ],

            self::FINANCE_MANAGER => [
                'users.view', 'users.adjust_credits',
                'logs.view',
                'settings.view',
                'billing.view', 'billing.manage', 'billing.refund',
                'billing.gateways.manage', 'billing.tax.manage',
                'analytics.view', 'analytics.costs.view', 'analytics.revenue.view',
            ],

            self::CONTENT_MANAGER => [
                'settings.view',
                'content.view', 'content.manage', 'content.publish',
                'themes.view', 'themes.manage',
                'branding.view', 'branding.manage',
                'files.view', 'files.upload',
            ],

            // Customers hold no admin permissions at all. Their access is
            // governed by plan entitlements, built in Phase 6.
            self::CUSTOMER => [],
        ];
    }

    /**
     * Permissions no role may hold except Super Admin, even if a custom role
     * tries to grant them. Defence in depth behind the matrix above.
     *
     * @return array<int, string>
     */
    public static function superAdminOnly(): array
    {
        return [
            'security.permissions.manage',
            'themes.custom_css',            // arbitrary CSS — §5 requires strict control
            'billing.gateways.credentials', // live payment credentials
            'users.impersonate',
            'diagnostics.security.view',
            'files.download_any',
        ];
    }

    /** @return array<int, string> */
    public static function adminRoles(): array
    {
        return [
            self::SUPER_ADMIN, self::ADMIN, self::SUPPORT_MANAGER,
            self::FINANCE_MANAGER, self::CONTENT_MANAGER,
        ];
    }
}
