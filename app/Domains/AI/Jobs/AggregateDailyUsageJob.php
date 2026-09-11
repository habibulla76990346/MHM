<?php

namespace App\Domains\AI\Jobs;

use App\Domains\AI\Models\ApiUsageLog;
use App\Domains\AI\Models\ExchangeRate;
use App\Domains\AI\Models\UsageDailySummary;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The nightly rollup behind every cost screen (§21).
 *
 * WHY A ROLLUP AT ALL. `api_usage_logs` gets a row per provider call — the
 * highest-volume table in the system. A dashboard covering a year cannot scan
 * it on every page load, and on the shared hosting this product is built for
 * it would not finish.
 *
 * WHY THE CONVERSION IS FROZEN HERE. Provider cost is recorded in the
 * provider's own currency. Converting it at display time with today's rate
 * would rewrite last quarter's margin every time the rupee moves. The rate
 * used is the one that applied ON the day being summarised, stored alongside
 * the native figure, so the number never changes afterwards.
 *
 * Rerunnable: the summary row is replaced, never added to, so running it twice
 * for the same day produces the same answer.
 */
class AggregateDailyUsageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly ?string $date = null) {}

    public function handle(): void
    {
        $day = $this->date ? Carbon::parse($this->date) : now()->subDay();
        $day = $day->startOfDay();

        $base = strtoupper((string) settings('billing.base_currency'));

        $rows = ApiUsageLog::query()
            ->selectRaw('provider_id, model_id, provider_currency')
            ->selectRaw('COUNT(*) as requests')
            ->selectRaw('SUM(CASE WHEN error_class IS NULL THEN 0 ELSE 1 END) as failures')
            ->selectRaw('SUM(input_tokens) as input_tokens')
            ->selectRaw('SUM(output_tokens) as output_tokens')
            ->selectRaw('SUM(provider_cost) as provider_cost')
            ->selectRaw('SUM(credit_cost) as credit_cost')
            ->whereBetween('occurred_at', [$day, $day->copy()->endOfDay()])
            ->groupBy('provider_id', 'model_id', 'provider_currency')
            ->get();

        foreach ($rows as $row) {
            $rate = ExchangeRate::rateOn((string) $row->provider_currency, $base, $day);

            UsageDailySummary::updateOrCreate(
                [
                    'summary_date' => $day->toDateString(),
                    'provider_id' => $row->provider_id,
                    'model_id' => $row->model_id,
                ],
                [
                    'requests' => (int) $row->requests,
                    'failures' => (int) $row->failures,
                    'input_tokens' => (int) $row->input_tokens,
                    'output_tokens' => (int) $row->output_tokens,
                    'provider_cost' => round((float) $row->provider_cost, 6),
                    'provider_currency' => $row->provider_currency,
                    // A missing rate records zero rather than an invented
                    // figure. A visible zero can be found and corrected by
                    // adding the rate and re-running; a guess cannot.
                    'provider_cost_base' => $rate === null
                        ? 0
                        : round((float) $row->provider_cost * $rate, 6),
                    'credit_cost' => round((float) $row->credit_cost, 6),
                    'p50_latency_ms' => $this->medianLatency($day, $row->provider_id, $row->model_id),
                ],
            );
        }

        $this->prune();
    }

    /**
     * The median, not the mean: one 30-second timeout would drag an average
     * far enough to make a good day look bad.
     */
    private function medianLatency(Carbon $day, ?int $providerId, ?int $modelId): ?int
    {
        $latencies = ApiUsageLog::query()
            ->whereBetween('occurred_at', [$day, $day->copy()->endOfDay()])
            ->where('provider_id', $providerId)
            ->where('model_id', $modelId)
            ->whereNotNull('latency_ms')
            ->where('latency_ms', '>', 0)
            ->orderBy('latency_ms')
            ->pluck('latency_ms');

        if ($latencies->isEmpty()) {
            return null;
        }

        return (int) $latencies[(int) floor(($latencies->count() - 1) / 2)];
    }

    /**
     * Old raw rows go; the summaries stay.
     *
     * An owner keeps the shape of their business for as long as they set,
     * without the per-call table growing without limit on a host that charges
     * by the megabyte.
     */
    private function prune(): void
    {
        $days = (int) settings('billing.usage_retention_days');

        if ($days <= 0) {
            return;
        }

        $cutoff = now()->subDays($days);

        // Chunked: one enormous DELETE on shared hosting is how a table gets
        // locked for long enough that the site stops answering.
        do {
            $deleted = ApiUsageLog::where('occurred_at', '<', $cutoff)->limit(1000)->delete();
        } while ($deleted > 0);

        do {
            $deleted = DB::table('routing_logs')->where('decided_at', '<', $cutoff)->limit(1000)->delete();
        } while ($deleted > 0);
    }
}
