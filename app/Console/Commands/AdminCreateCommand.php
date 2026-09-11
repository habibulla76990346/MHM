<?php

namespace App\Console\Commands;

use App\Domains\Identity\Models\UserProfile;
use App\Domains\Security\Services\ActivityLogger;
use App\Domains\Security\Services\PermissionRegistry;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Validator;

/**
 * Creates the first administrator (delivery guide 11).
 *
 * Deliberately a command rather than a seeder: a seeder that invents an
 * account with a known password is a backdoor waiting to reach production.
 */
class AdminCreateCommand extends Command
{
    protected $signature = 'aziv:admin:create
                            {--name= : Display name}
                            {--email= : Email address}
                            {--role=Super Admin : Role to assign}';

    protected $description = 'Create an administrator account';

    public function handle(ActivityLogger $log): int
    {
        $name = $this->option('name') ?: $this->ask('Name');
        $email = $this->option('email') ?: $this->ask('Email address');
        $password = $this->secret('Password (input hidden)');
        $role = $this->option('role');

        $validator = Validator::make(
            compact('name', 'email', 'password'),
            [
                'name' => ['required', 'string', 'max:120'],
                'email' => ['required', 'email', 'max:190', 'unique:users,email'],
                'password' => ['required', Password::min(12)->mixedCase()->numbers()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        if (! in_array($role, PermissionRegistry::adminRoles(), true)) {
            $this->error('Unknown role ['.$role.']. Available: '.implode(', ', PermissionRegistry::adminRoles()));

            return self::FAILURE;
        }

        $user = DB::transaction(function () use ($name, $email, $password, $role) {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'status' => User::STATUS_ACTIVE,
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();
            UserProfile::create(['user_id' => $user->getKey()]);
            $user->assignRole($role);

            return $user;
        });

        $log->log('admin.created', $user, null, ['email' => $user->email, 'role' => $role]);

        $this->newLine();
        $this->info('Administrator created.');
        $this->line('  '.$user->email.'  ·  '.$role);
        $this->line('  Sign in at '.rtrim(config('app.url'), '/').'/admin');
        $this->newLine();

        return self::SUCCESS;
    }
}
