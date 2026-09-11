<?php

namespace App\Domains\Diagnostics\Checks;

use App\Domains\AI\Contracts\ContributesDiagnostics;
use App\Domains\AI\Models\AiProvider;
use App\Domains\AI\Services\ProviderRegistry;
use App\Domains\Diagnostics\Support\Category;
use App\Domains\Diagnostics\Support\CheckResult;
use App\Domains\Diagnostics\Support\Responsibility;
use App\Domains\Diagnostics\Support\Severity;
use App\Domains\Diagnostics\Support\Status;

/**
 * AI provider connectivity, authentication and catalog freshness
 * (Owner Addendum G, Phase 3).
 *
 * This check does NOT know how any provider signals trouble — it asks each
 * adapter to report on itself. Centralising that knowledge would put
 * provider-specific behaviour back above the adapter layer, which is precisely
 * what §12 exists to prevent.
 *
 * COSTS MONEY, so it is manual-only. Provider connectivity tests are
 * authenticated calls; running them unattended every few minutes across every
 * provider would quietly spend an owner's money on diagnostics.
 */
class AiProviderCheck extends BaseCheck
{
    /** Beyond this, a catalog is stale enough to be worth saying so. */
    private const CATALOG_STALE_DAYS = 30;

    public function __construct(private readonly ProviderRegistry $registry) {}

    public function key(): string
    {
        return 'ai.providers';
    }

    public function title(): string
    {
        return 'AI provider connectivity';
    }

    public function category(): Category
    {
        return Category::AiProviders;
    }

    public function isSafeToRunAutomatically(): bool
    {
        return false;
    }

    public function costsMoney(): bool
    {
        return true;
    }

    public function isApplicable(): bool
    {
        try {
            return AiProvider::usable()->exists();
        } catch (\Throwable) {
            // Before the migration has run, this is not applicable rather than
            // broken — and it must not take the System Health page down.
            return false;
        }
    }

    public function run(): CheckResult
    {
        $providers = AiProvider::usable()->with('credentials')->get();

        if ($providers->isEmpty()) {
            return new CheckResult(
                key: $this->key(),
                title: $this->title(),
                category: $this->category(),
                status: Status::Grey,
                severity: Severity::Informational,
                responsibility: Responsibility::Configuration,
                technicalReason: 'No AI provider is active yet.',
                recommendedAction: 'Aziv AI cannot answer anything until at least one provider is set up.',
                adminAction: 'Open Admin → AI Providers, add a provider, paste its API key and switch it on.',
            );
        }

        $failures = [];
        $working = 0;
        $stale = [];

        foreach ($providers as $provider) {
            $adapter = $this->registry->for($provider);

            if (! $adapter) {
                $failures[] = $provider->name.': no adapter is installed for its type ('.$provider->adapter_type.')';

                continue;
            }

            if (! $provider->activeCredential()) {
                $failures[] = $provider->name.': no API key has been added';

                continue;
            }

            if ($adapter instanceof ContributesDiagnostics) {
                foreach ($adapter->diagnostics() as $result) {
                    $result->status === Status::Green
                        ? $working++
                        : $failures[] = $provider->name.': '.$result->technicalReason;
                }
            } else {
                $result = $adapter->testConnection();
                $result->success
                    ? $working++
                    : $failures[] = $provider->name.': '.$result->label();
            }

            // Catalog freshness: a provider whose models have not been read
            // for a month is probably offering models this owner cannot see.
            $lastSync = $provider->syncLogs()->where('status', 'success')->value('finished_at');

            if ($lastSync === null || now()->diffInDays($lastSync) > self::CATALOG_STALE_DAYS) {
                $stale[] = $provider->name;
            }
        }

        if ($failures !== []) {
            return new CheckResult(
                key: $this->key(),
                title: $this->title(),
                category: $this->category(),
                status: Status::Red,
                severity: Severity::High,
                responsibility: Responsibility::Provider,
                technicalReason: implode(' · ', $failures),
                recommendedAction: 'One or more AI providers cannot be reached, so requests routed to them will fail.',
                adminAction: 'Open Admin → AI Providers and use Test connection on each one listed above.',
            );
        }

        if ($stale !== []) {
            return new CheckResult(
                key: $this->key(),
                title: $this->title(),
                category: $this->category(),
                status: Status::Yellow,
                severity: Severity::Low,
                responsibility: Responsibility::Configuration,
                technicalReason: sprintf(
                    '%d provider(s) reachable. Model catalog not refreshed in over %d days: %s.',
                    $working,
                    self::CATALOG_STALE_DAYS,
                    implode(', ', $stale),
                ),
                recommendedAction: 'Everything works, but new models released since the last refresh are not offered to your customers.',
                adminAction: 'Open Admin → AI Models and press Refresh models.',
            );
        }

        return CheckResult::pass(
            $this->key(),
            $this->title(),
            $this->category(),
            Responsibility::Provider,
            sprintf('%d provider(s) reachable, model catalogs current.', $working),
        );
    }
}
