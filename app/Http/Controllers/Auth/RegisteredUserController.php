<?php

namespace App\Http\Controllers\Auth;

use App\Domains\Identity\Models\UserProfile;
use App\Domains\Identity\Services\PasswordPolicy;
use App\Domains\Identity\Services\SessionGuard;
use App\Domains\Security\Services\ActivityLogger;
use App\Domains\Security\Services\PermissionRegistry;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class RegisteredUserController extends Controller
{
    public function create(): Response
    {
        abort_unless(settings('auth.registration_enabled'), 404);

        return response()->view('auth.register', [
            'passwordHint' => PasswordPolicy::describe(),
        ]);
    }

    public function store(Request $request, SessionGuard $sessions, ActivityLogger $log): RedirectResponse
    {
        abort_unless(settings('auth.registration_enabled'), 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', 'confirmed', PasswordPolicy::rule()],
        ]);

        $requiresVerification = (bool) settings('auth.require_email_verification');

        $user = DB::transaction(function () use ($validated, $requiresVerification) {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
                'status' => $requiresVerification ? User::STATUS_PENDING : User::STATUS_ACTIVE,
                'locale' => settings('system.default_locale'),
                'timezone' => settings('system.default_timezone'),
            ]);

            UserProfile::create(['user_id' => $user->getKey()]);
            $user->assignRole(PermissionRegistry::CUSTOMER);

            return $user;
        });

        event(new Registered($user));

        $log->log('auth.registered', $user, null, [
            'email' => $user->email,
            'status' => $user->status,
        ]);

        Auth::login($user);
        $request->session()->regenerate();
        $sessions->record($user, $request);

        return redirect()->intended(route('dashboard'));
    }
}
