<?php

namespace App\Http\Middleware;

use App\Support\Installer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The installer answers only on a system that has not been installed.
 *
 * A 404, NOT A 403. A 403 confirms the route exists, and the existence of an
 * installer on a live site is itself information worth having if you are
 * looking for a way in. As far as anyone outside is concerned, an installed
 * Aziv AI has no installer.
 *
 * `Installer::isOpen()` is the single place that decides, and it needs three
 * conditions to agree — a switch, a lock file, and whether the database
 * already holds accounts. The last is the one an attacker cannot arrange.
 */
class EnsureInstallerIsOpen
{
    public function __construct(private readonly Installer $installer) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->installer->isOpen(), 404);

        return $next($request);
    }
}
