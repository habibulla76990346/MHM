<?php

namespace App\Providers;

use App\Domains\Security\Services\ActivityLogger;
use App\Domains\Security\Services\PermissionRegistry;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class SecurityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ActivityLogger::class);
    }

    public function boot(): void
    {
        /**
         * Super Admin holds every permission implicitly.
         *
         * This is deliberate rather than granting them the full permission
         * list: a permission added in a later phase would otherwise be denied
         * to the owner until someone remembered to re-seed, which is how
         * people get locked out of their own platform.
         *
         * Returning null (not false) for everyone else preserves normal
         * deny-by-default evaluation.
         */
        Gate::before(function (User $user, string $ability) {
            if ($user->isSuperAdmin()) {
                return true;
            }

            // null keeps normal deny-by-default evaluation for everyone else.
            //
            // The hard denial of Super-Admin-only permissions is NOT here: it
            // lives in User::hasPermissionTo(). spatie/laravel-permission
            // registers its own Gate::before, Laravel returns the first
            // non-null before result, and ordering between two before
            // callbacks is not dependable — a denial placed here is silently
            // inert whenever Spatie's callback answers first.
            return null;
        });
    }
}
