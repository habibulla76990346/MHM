<?php

namespace App\Domains\AI\Jobs;

use App\Domains\AI\Models\ApiUsageLog;
use App\Domains\AI\Models\ExchangeRate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Today's rate for every currency the platform actually pays in (§13).
 *
 * THE SOURCE IS A SETTING, not a hard-coded URL. Rate feeds appear, disappear
 * and start charging; an owner must be able to point this somewhere else
 * without a code change (Rule: environment is configuration, not
 * architecture). The response is read as `rates.<CODE>` — the shape every
 * common feed uses — and anything else is simply not stored.
 *
 * A FAILURE CHANGES NOTHING. The last known rate stays in force, because
 * `ExchangeRate::rateOn()` takes the newest row at or before the date it is asked
 * about. A feed being down must never make yesterday's margin unreportable.
 */
class RefreshExchangeRatesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $base = strtoupper((string) settings('billing.base_currency'));
        $endpoint = trim((string) settings('billing.exchange_rate_source'));

        if ($endpoint === '') {
            return;
        }

        $needed = $this->currenciesInUse($base);

        if ($needed === []) {
            return;
        }

        try {
            $response = Http::timeout(20)
                ->acceptJson()
                ->get(str_replace('{base}', $base, $endpoint));
        } catch (\Throwable) {
            // Silent by design: see the note above. The previous rate holds.
            return;
        }

        if ($response->failed()) {
            Log::warning('Exchange rate refresh failed.', ['status' => $response->status()]);

            return;
        }

        foreach ($needed as $currency) {
            $rate = data_get($response->json(), 'rates.'.$currency);

            if (! is_numeric($rate) || (float) $rate <= 0) {
                continue;
            }

            ExchangeRate::updateOrCreate(
                [
                    'base_currency' => $base,
                    'quote_currency' => $currency,
                    'effective_on' => now()->toDateString(),
                ],
                ['rate' => (float) $rate, 'source' => 'api'],
            );
        }
    }

    /**
     * Only the currencies that have actually been billed in.
     *
     * Storing a rate for every currency in the world would be a daily write of
     * rows nothing will ever read.
     *
     * @return array<int, string>
     */
    private function currenciesInUse(string $base): array
    {
        return ApiUsageLog::query()
            ->distinct()
            ->where('provider_currency', '!=', $base)
            ->pluck('provider_currency')
            ->map(fn ($c) => strtoupper((string) $c))
            ->unique()
            ->values()
            ->all();
    }
}
