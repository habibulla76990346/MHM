<?php

namespace App\Http\Middleware;

use App\Domains\Security\Services\PermissionRegistry;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin-controlled maintenance mode (blueprint §6, §24).
 *
 * Distinct from `php artisan down`, which needs shell access — this is a
 * setting an administrator flips from the panel, which matters on hosting
 * where SSH may not exist.
 */
class EnsureNotInMaintenance
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! settings('system.maintenance_mode')) {
            return $next($request);
        }

        // Administrators keep access so they can turn it back off.
        if (Auth::check() && Auth::user()->hasAnyRole(PermissionRegistry::adminRoles())) {
            return $next($request);
        }

        // The health endpoint must stay reachable for uptime monitoring.
        if ($request->is('up')) {
            return $next($request);
        }

        return response()->view('maintenance', [
            'message' => settings('system.maintenance_message'),
        ], 503);
    }
}
