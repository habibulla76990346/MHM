<?php

namespace App\Console\Commands;

use App\Domains\Identity\Models\UserProfile;
use App\Domains\Security\Services\PermissionRegistry;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Creates the account the responsive gate signs in as.
 *
 * Development only: it refuses to run in production, so this can never become
 * a known-password account on a live site.
 */
class TestUserCommand extends Command
{
    /** The customer account the responsive gate signs in as. */
    public const EMAIL = 'responsive@aziv.test';

    /** The administrator it signs in as for the Admin Panel screens. */
    public const ADMIN_EMAIL = 'responsive-admin@aziv.test';

    protected $signature = 'aziv:test-user
                            {--email=}
                            {--password=Responsive-Test-2026}
                            {--admin : Create the Admin Panel account the gate checks admin screens as}';

    protected $description = 'Create or reset the local account used by the responsive test gate';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Refusing to create a known-password account in production.');

            return self::FAILURE;
        }

        $isAdmin = (bool) $this->option('admin');

        $email = $this->option('email')
            ?: ($isAdmin ? self::ADMIN_EMAIL : self::EMAIL);

        $user = User::withTrashed()->firstOrNew(['email' => $email]);
        $user->fill([
            'name' => $isAdmin ? 'Responsive Test Admin' : 'Responsive Test',
            'password' => $this->option('password'),
            'status' => User::STATUS_ACTIVE,
        ]);
        $user->deleted_at = null;
        $user->email_verified_at = now();
        $user->save();

        UserProfile::firstOrCreate(['user_id' => $user->getKey()]);

        $role = $isAdmin ? PermissionRegistry::SUPER_ADMIN : PermissionRegistry::CUSTOMER;

        if (! $user->hasRole($role)) {
            $user->assignRole($role);
        }

        $this->info('Test account ready: '.$email);

        return self::SUCCESS;
    }
}
