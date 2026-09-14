<?php

namespace App\Providers;

use App\Domains\Security\Services\ActivityLogger;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Events\RoleAttachedEvent;
use Spatie\Permission\Events\RoleDetachedEvent;
use Spatie\Permission\Models\Role;

class SecurityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ActivityLogger::class);
    }

    public function boot(): void
    {
        $this->recordAccountChanges();

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

    /**
     * §23: role and account-status changes are audited.
     *
     * ON THE MODEL AND THE FRAMEWORK'S OWN EVENTS, not on a screen. There is no
     * user-management resource in the panel yet, and when there is, this
     * already covers it — along with a console command, a seeder, and whatever
     * somebody writes next. An audit rule attached to one screen is an audit
     * rule that ends the day a second screen appears.
     *
     * SUSPENDING AN ACCOUNT AND GRANTING IT AN ADMIN ROLE are the two changes
     * whose "who did this, and when?" matters most, and neither had a record
     * before Phase 9 went looking.
     */
    private function recordAccountChanges(): void
    {
        User::updated(function (User $user) {
            if (! $user->wasChanged('status')) {
                return;
            }

            app(ActivityLogger::class)->log(
                action: 'user.status_changed',
                subject: $user,
                before: ['status' => $user->getOriginal('status')],
                after: ['status' => $user->status],
            );
        });

        Event::listen(RoleAttachedEvent::class, function (RoleAttachedEvent $event) {
            $this->recordRoleChange('user.role_granted', $event->model, $event->rolesOrIds);
        });

        Event::listen(RoleDetachedEvent::class, function (RoleDetachedEvent $event) {
            $this->recordRoleChange('user.role_revoked', $event->model, $event->rolesOrIds);
        });
    }

    private function recordRoleChange(string $action, object $model, mixed $roles): void
    {
        if (! $model instanceof User) {
            return;
        }

        app(ActivityLogger::class)->log(
            action: $action,
            subject: $model,
            after: ['roles' => $this->roleNames($roles)],
        );
    }

    /**
     * Role NAMES, whatever the event handed over.
     *
     * Spatie passes role ids internally and objects when called directly, and
     * says so in its own docblock. An audit entry reading `["5"]` answers
     * nothing six months later, which is the entire reason the entry exists.
     *
     * @return array<int, string>
     */
    private function roleNames(mixed $roles): array
    {
        return collect(is_iterable($roles) ? $roles : [$roles])
            ->map(function ($role) {
                if (is_object($role)) {
                    return (string) ($role->name ?? $role->getKey());
                }

                // An id. Resolved to a name, falling back to the id itself if
                // the role has since been deleted — a record with an id in it
                // still beats no record.
                return (string) (Role::find($role)?->name ?? $role);
            })
            ->all();
    }
}
