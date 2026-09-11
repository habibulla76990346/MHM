<?php

namespace App\Filament\Pages;

use App\Domains\AI\Models\ApiUsageLog;
use App\Domains\AI\Models\ExchangeRate;
use App\Domains\AI\Models\UsageDailySummary;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

/**
 * ADMIN → Usage and Costs (blueprint §21).
 *
 * WHAT THIS SCREEN IS FOR: an owner asking "am I making money on this?" and
 * getting an answer they can act on, in their own currency.
 *
 * READ FROM THE NIGHTLY ROLLUP, not from the per-call table. `api_usage_logs`
 * grows by a row per provider call; a year-long view that scanned it would not
 * finish on the shared hosting this product is built for. Today is the one
 * exception — it has not been summarised yet, so it is read live and labelled
 * as provisional rather than quietly omitted.
 *
 * EVERY FIGURE IS STORED, NEVER RECOMPUTED. The conversion into the owner's
 * currency was frozen at the rate that applied on each day. Recomputing at
 * today's rate would move last quarter's margin every time the rupee does.
 */
class UsageAndCosts extends Page
{
    protected static ?string $navigationLabel = 'Usage and Costs';

    protected static ?string $title = 'Usage and Costs';

    protected static ?string $slug = 'usage-and-costs';

    protected static ?int $navigationSort = 40;

    protected string $view = 'filament.pages.usage-and-costs';

    /** Days back. In the URL so a view an owner is looking at can be shared. */
    #[Url]
    public int $days = 30;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('analytics.costs.view') ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function setRange(int $days): void
    {
        $this->days = in_array($days, [7, 30, 90, 365], true) ? $days : 30;
    }

    public function baseCurrency(): string
    {
        return strtoupper((string) settings('billing.base_currency'));
    }

    private function since(): Carbon
    {
        return now()->subDays(max(1, $this->days))->startOfDay();
    }

    /**
     * The rolled-up days.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function rows(): array
    {
        $summaries = UsageDailySummary::with(['provider', 'model'])
            ->where('summary_date', '>=', $this->since()->toDateString())
            ->orderByDesc('summary_date')
            ->get();

        return $summaries->map(fn (UsageDailySummary $s) => [
            'date' => $s->summary_date?->toDateString(),
            'provider' => $s->provider?->name ?? '—',
            'model' => $s->model?->display_name ?? '—',
            'requests' => (int) $s->requests,
            'failures' => (int) $s->failures,
            'tokens' => (int) $s->input_tokens + (int) $s->output_tokens,
            'cost' => (float) $s->provider_cost_base,
            'revenue' => (float) $s->credit_cost,
            'margin' => $s->margin(),
            'latency' => $s->p50_latency_ms,
        ])->all();
    }

    /** @return array<string, float|int> */
    #[Computed]
    public function totals(): array
    {
        $rows = $this->rows();
        $today = $this->today();

        $cost = array_sum(array_column($rows, 'cost')) + $today['cost'];
        $revenue = array_sum(array_column($rows, 'revenue')) + $today['revenue'];

        return [
            'requests' => array_sum(array_column($rows, 'requests')) + $today['requests'],
            'failures' => array_sum(array_column($rows, 'failures')) + $today['failures'],
            'tokens' => array_sum(array_column($rows, 'tokens')) + $today['tokens'],
            'cost' => $cost,
            'revenue' => $revenue,
            'margin' => $revenue - $cost,
            'margin_percent' => $revenue > 0 ? round(($revenue - $cost) / $revenue * 100, 1) : 0.0,
        ];
    }

    /**
     * Today, read live.
     *
     * Marked provisional on the screen: it has not been through the nightly
     * rollup, so its conversion uses the newest rate on file rather than a
     * frozen one. Omitting today entirely would be worse — an owner checking
     * the effect of a change they made this morning would see nothing.
     *
     * @return array<string, float|int>
     */
    #[Computed]
    public function today(): array
    {
        $base = $this->baseCurrency();
        $cost = 0.0;

        $rows = ApiUsageLog::query()
            ->selectRaw('provider_currency, SUM(provider_cost) as provider_cost, SUM(credit_cost) as credit_cost')
            ->selectRaw('COUNT(*) as requests, SUM(input_tokens + output_tokens) as tokens')
            ->selectRaw('SUM(CASE WHEN error_class IS NULL THEN 0 ELSE 1 END) as failures')
            ->where('occurred_at', '>=', now()->startOfDay())
            ->groupBy('provider_currency')
            ->get();

        foreach ($rows as $row) {
            $rate = ExchangeRate::rateOn((string) $row->provider_currency, $base);
            $cost += $rate === null ? 0.0 : (float) $row->provider_cost * $rate;
        }

        return [
            'requests' => (int) $rows->sum('requests'),
            'failures' => (int) $rows->sum('failures'),
            'tokens' => (int) $rows->sum('tokens'),
            'cost' => round($cost, 4),
            'revenue' => round((float) $rows->sum('credit_cost'), 4),
        ];
    }

    /**
     * Where the money goes, by provider.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function byProvider(): array
    {
        $grouped = [];

        foreach ($this->rows() as $row) {
            $key = $row['provider'];

            $grouped[$key] ??= ['provider' => $key, 'requests' => 0, 'cost' => 0.0, 'revenue' => 0.0];
            $grouped[$key]['requests'] += $row['requests'];
            $grouped[$key]['cost'] += $row['cost'];
            $grouped[$key]['revenue'] += $row['revenue'];
        }

        foreach ($grouped as &$row) {
            $row['margin'] = $row['revenue'] - $row['cost'];
        }

        usort($grouped, fn ($a, $b) => $b['cost'] <=> $a['cost']);

        return $grouped;
    }

    /**
     * Currencies used that have no rate on file.
     *
     * Surfaced rather than silently treated as zero: a cost figure missing a
     * day's provider spend is a figure an owner would act on wrongly.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function missingRates(): array
    {
        $base = $this->baseCurrency();

        return ApiUsageLog::query()
            ->distinct()
            ->where('occurred_at', '>=', $this->since())
            ->where('provider_currency', '!=', $base)
            ->pluck('provider_currency')
            ->map(fn ($c) => strtoupper((string) $c))
            ->unique()
            ->filter(fn (string $c) => ExchangeRate::rateOn($c, $base) === null)
            ->values()
            ->all();
    }

    public function money(float $amount): string
    {
        return $this->baseCurrency().' '.number_format($amount, 2);
    }
}
