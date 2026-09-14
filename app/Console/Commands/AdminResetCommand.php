<?php

namespace App\Console\Commands;

use App\Domains\Identity\Services\MfaService;
use App\Domains\Security\Services\ActivityLogger;
use App\Domains\Security\Services\PermissionRegistry;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Recover an administrator who cannot sign in (delivery guide 11, Addendum E §4).
 *
 * THIS IS THE ONE SITUATION THE ADMIN PANEL CANNOT HELP WITH, because the
 * Admin Panel is what the person cannot reach. Everything else in Aziv AI has
 * a screen; this deliberately does not, because a screen that resets an
 * administrator's password without being signed in IS the back door.
 *
 * So the authority here is the shell — whoever can run this already owns the
 * server and could read `.env` anyway. That is the whole security model, and
 * it is why there is no emailed reset, no master password, and no support
 * address that can let anybody back in.
 *
 * THE PASSWORD IS PROMPTED, NEVER AN OPTION. An option lands in the shell
 * history, in `ps`, and in the audit trail of whatever ran the command. The
 * account it belongs to is worth more than the convenience.
 *
 * TWO-FACTOR IS CLEARED SEPARATELY AND ASKS FIRST. A lost phone and a
 * forgotten password are different accidents, and someone recovering from one
 * should not silently have the other protection removed.
 */
class AdminResetCommand extends Command
{
    protected $signature = 'aziv:admin:reset
                            {--email= : The account to recover}
                            {--clear-mfa : Also remove two-factor authentication from the account}
                            {--unlock : Return a suspended or locked account to active}';

    protected $description = 'Reset an administrator password, clear two-factor, or unlock an account';

    public function handle(ActivityLogger $log, MfaService $mfa): int
    {
        $email = $this->option('email') ?: $this->ask('Email address');

        $user = User::where('email', $email)->first();

        if (! $user) {
            // Named, because this is a console an owner is already trusted at.
            // The reticence that makes sense on a login form is only confusing
            // here — the person is looking at the database from the outside.
            $this->error('No account exists with the address ['.$email.'].');

            return self::FAILURE;
        }

        if (! $user->hasAnyRole(PermissionRegistry::adminRoles())) {
            $this->error('['.$email.'] is not an administrator. Customer passwords are reset by the customer, from the sign-in page.');

            return self::FAILURE;
        }

        $this->line('Recovering: '.$user->name.'  ·  '.$user->email.'  ·  '.$user->getRoleNames()->implode(', '));
        $this->newLine();

        $did = [];

        if ($this->option('unlock')) {
            $before = $user->status;
            $user->forceFill(['status' => User::STATUS_ACTIVE])->save();
            $log->log('admin.unlocked', $user, ['status' => $before], ['status' => User::STATUS_ACTIVE]);
            $did[] = 'unlocked';
            $this->info('Account is active again.');
        }

        if ($this->option('clear-mfa')) {
            if ($user->mfa_confirmed_at === null) {
                $this->line('Two-factor was not enabled on this account; nothing to clear.');
            } elseif ($this->confirm('Remove two-factor authentication from this account?', true)) {
                $mfa->disable($user);
                $did[] = 'two-factor cleared';
                $this->info('Two-factor removed. Enrol again from the profile screen as soon as you are back in.');
            }
        }

        // A run with only --unlock or only --clear-mfa should not force a new
        // password on somebody who still knows theirs.
        if ($this->option('unlock') || $this->option('clear-mfa')) {
            if (! $this->confirm('Set a new password as well?', ! $this->option('unlock') && ! $this->option('clear-mfa'))) {
                return $this->finish($did);
            }
        }

        $password = $this->secret('New password (input hidden)');
        $again = $this->secret('Repeat it');

        if ($password !== $again) {
            $this->error('The two passwords do not match. Nothing was changed.');

            return self::FAILURE;
        }

        $validator = Validator::make(
            ['password' => $password],
            ['password' => ['required', Password::min(12)->mixedCase()->numbers()]],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user->forceFill(['password' => $password])->save();

        // The VALUE is never recorded, here or anywhere: an audit table is
        // read by more people than an inbox is. That it happened, and to
        // whom, is the record worth keeping.
        $log->log('admin.password_reset', $user, null, ['email' => $user->email, 'via' => 'console']);

        $did[] = 'password reset';
        $this->info('Password changed.');

        return $this->finish($did);
    }

    /** @param  array<int, string>  $did */
    private function finish(array $did): int
    {
        $this->newLine();
        $this->line($did === [] ? 'Nothing was changed.' : 'Done: '.implode(', ', $did).'.');
        $this->newLine();

        return self::SUCCESS;
    }
}
