<?php

use App\Http\Controllers\Install\InstallController;
use App\Http\Middleware\EnsureInstallerIsOpen;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The browser installer (Owner Addendum E §4)
|--------------------------------------------------------------------------
|
| A SEPARATE FILE so it is obvious what it contains and so an owner who will
| never need it can see exactly what to delete. Every route is behind
| `EnsureInstallerIsOpen`, which 404s the moment the system has been installed
| — by lock file, by there being accounts in the database, or by the switch.
|
| THROTTLED, because the database step is a credential guess. Without a limit
| this would be an oracle for brute-forcing a database password on a host where
| the database is reachable from the web server.
|
*/

Route::middleware(['web', EnsureInstallerIsOpen::class])
    ->prefix('install')
    ->name('install.')
    ->group(function () {
        Route::get('/', [InstallController::class, 'requirements'])->name('requirements');

        Route::get('database', [InstallController::class, 'database'])->name('database');
        Route::post('database', [InstallController::class, 'testDatabase'])
            ->middleware('throttle:10,1')->name('database.test');

        Route::get('application', [InstallController::class, 'application'])->name('application');
        Route::post('application', [InstallController::class, 'saveApplication'])
            ->middleware('throttle:10,1')->name('application.save');

        Route::get('run', [InstallController::class, 'run'])->name('run');
        Route::post('run', [InstallController::class, 'execute'])
            ->middleware('throttle:5,1')->name('run.execute');

        Route::get('administrator', [InstallController::class, 'administrator'])->name('administrator');
        Route::post('administrator', [InstallController::class, 'createAdministrator'])
            ->middleware('throttle:5,1')->name('administrator.create');
    });

/*
| The success page sits OUTSIDE that group on purpose. By the time it renders
| the installer is locked, and the middleware above would 404 it — which reads
| like the install failed at the last step. Its own gate is the session flag
| written a moment earlier, so only the browser that just installed can see it.
*/
Route::middleware('web')
    ->get('install/finished', [InstallController::class, 'finished'])
    ->name('install.finished');
