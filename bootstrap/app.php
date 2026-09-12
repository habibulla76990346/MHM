<?php

use App\Domains\Diagnostics\Console\DiagnoseCommand;
use App\Http\Middleware\EnforceSessionPolicy;
use App\Http\Middleware\EnsureNotInMaintenance;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        DiagnoseCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            EnsureNotInMaintenance::class,
            EnforceSessionPolicy::class,
        ]);

        // A payment gateway has no session and no CSRF token. What
        // authenticates its webhook is the signature over the raw body, which
        // is verified for every delivery and is not optional — see
        // WebhookProcessor. Excluding the path here is what lets that check be
        // the only one, rather than a second-best after a token that could
        // never be present.
        $middleware->validateCsrfTokens(except: [
            'webhooks/payments/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
