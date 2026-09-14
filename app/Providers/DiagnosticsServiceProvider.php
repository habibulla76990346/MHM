<?php

namespace App\Providers;

use App\Domains\Diagnostics\CheckRegistry;
use App\Domains\Diagnostics\Checks;
use Illuminate\Support\ServiceProvider;

class DiagnosticsServiceProvider extends ServiceProvider
{
    /**
     * Phase 0 check set. Each later phase appends its own:
     *   Phase 2 — storage, mail, cache/session
     *   Phase 3 — AI provider connectivity, auth, model catalog freshness
     *   Phase 6 — payment gateways, webhooks, tax configuration
     */
    private const CHECKS = [
        Checks\EnvironmentCheck::class,
        Checks\PhpVersionCheck::class,
        Checks\PhpExtensionsCheck::class,
        Checks\PhpLimitsCheck::class,
        Checks\FilesystemCheck::class,
        Checks\DatabaseCheck::class,
        Checks\OutboundHttpsCheck::class,
        Checks\ProductionSecurityCheck::class,
        // Phase 9. The scheduler and the queue are checked before anything
        // that depends on them, because "nothing is running your background
        // work" explains most of what follows it.
        Checks\SchedulerCheck::class,
        Checks\QueueCheck::class,
        Checks\ApplicationStateCheck::class,
        Checks\StorageSpaceCheck::class,
        // Manual-only: it makes real HTTP requests to this server's own front
        // door, and a nightly job doing that adds noise for no new answer.
        Checks\ExposureCheck::class,
        // Manual-only: provider tests are authenticated calls, so running them
        // unattended would spend the owner's money on diagnostics.
        Checks\AiProviderCheck::class,
        // Payments read configuration only — they never call a gateway, so
        // they are safe to run unattended. Connectivity is tested from the
        // gateway screen, by someone who meant to.
        Checks\MailDeliveryCheck::class,
        Checks\PaymentGatewayCheck::class,
        Checks\TaxConfigurationCheck::class,
    ];

    public function register(): void
    {
        $this->app->singleton(CheckRegistry::class, function ($app) {
            $registry = new CheckRegistry;
            foreach (self::CHECKS as $class) {
                $registry->register($app->make($class));
            }

            return $registry;
        });
    }
}
