<?php

namespace Tests\Feature\AI;

use App\Domains\AI\Adapters\OpenAiAdapter;
use App\Domains\AI\DTO\UsageMetrics;
use App\Domains\AI\Jobs\AggregateDailyUsageJob;
use App\Domains\AI\Jobs\RefreshExchangeRatesJob;
use App\Domains\AI\Models\AiModel;
use App\Domains\AI\Models\AiModelCapability;
use App\Domains\AI\Models\AiModelPrice;
use App\Domains\AI\Models\AiProvider;
use App\Domains\AI\Models\AiProviderBudget;
use App\Domains\AI\Models\AiProviderBudgetAlert;
use App\Domains\AI\Models\AiProviderCredential;
use App\Domains\AI\Models\ApiUsageLog;
use App\Domains\AI\Models\CredentialUsageCounter;
use App\Domains\AI\Models\ExchangeRate;
use App\Domains\AI\Models\UsageDailySummary;
use App\Domains\AI\Support\Capability;
use App\Domains\AI\Usage\BudgetGuard;
use App\Domains\AI\Usage\UsageRecorder;
use App\Domains\Chat\Models\MessageUsage;
use App\Domains\Chat\Services\ChatService;
use App\Domains\Chat\Services\ConversationService;
use App\Domains\Security\Services\PermissionRegistry;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Phase 5 gate, third half: what a call cost, and what it earned.
 *
 * The two things these assertions exist to stop:
 *
 *   1  a cost computed at TODAY'S price, which silently rewrites the
 *      profitability of every month already reported;
 *   2  a figure that does not reconcile with what the provider itself said it
 *      used, which makes a margin report a guess with a decimal point.
 */
class UsageCostTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private AiModel $model;

    private AiProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::factory()->create();
        $this->user->assignRole(PermissionRegistry::CUSTOMER);
        $this->user = $this->user->fresh();

        $this->provider = AiProvider::create([
            'name' => 'Costed',
            'adapter_type' => OpenAiAdapter::KEY,
            'api_base_url' => 'https://costed.test/v1',
            'status' => AiProvider::STATUS_ACTIVE,
        ]);

        AiProviderCredential::create([
            'provider_id' => $this->provider->getKey(),
            'label' => 'Key',
            'credential' => 'sk-costed-KEYKEYKEY1234',
        ]);

        $this->model = AiModel::create([
            'provider_id' => $this->provider->getKey(),
            'model_identifier' => 'costed-1',
            'display_name' => 'Costed One',
            'is_enabled' => true,
            'context_window' => 32000,
            'max_output_tokens' => 2048,
        ]);

        AiModelCapability::syncForModel($this->model, [Capability::CHAT, Capability::STREAMING]);

        $this->priceAt(now()->subMonths(2), input: 0.001, output: 0.002, credits: 4);
    }

    private function priceAt(\DateTimeInterface $from, float $input, float $output, float $credits): void
    {
        AiModelPrice::create([
            'model_id' => $this->model->getKey(),
            'unit' => 'per_1k_input',
            'provider_cost' => $input,
            'credit_cost' => $credits,
            'currency' => 'USD',
            'effective_from' => $from,
        ]);

        AiModelPrice::create([
            'model_id' => $this->model->getKey(),
            'unit' => 'per_1k_output',
            'provider_cost' => $output,
            'credit_cost' => $credits * 2,
            'currency' => 'USD',
            'effective_from' => $from,
        ]);
    }

    // -- reconciliation -------------------------------------------------------

    public function test_a_chat_turn_records_exactly_what_the_provider_said_it_used(): void
    {
        Http::fake(['*' => Http::response([
            'model' => 'costed-1',
            'choices' => [['message' => ['content' => 'Costed answer'], 'finish_reason' => 'stop']],
            // The provider's own figures. Everything downstream must reconcile
            // against THESE, never against an estimate made alongside them.
            'usage' => ['prompt_tokens' => 1500, 'completion_tokens' => 500],
        ])]);

        $conversation = app(ConversationService::class)->start($this->user);
        $turn = app(ChatService::class)->beginTurn($conversation, 'What does this cost?');
        app(ChatService::class)->complete($turn['assistant']);

        $log = ApiUsageLog::latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame(1500, $log->input_tokens);
        $this->assertSame(500, $log->output_tokens);
        $this->assertSame(2000, $log->total_tokens);

        // 1.5 × 0.001 + 0.5 × 0.002 = 0.0025
        $this->assertEqualsWithDelta(0.0025, (float) $log->provider_cost, 0.0000001);
        // 1.5 × 4 + 0.5 × 8 = 10 credits
        $this->assertEqualsWithDelta(10.0, (float) $log->credit_cost, 0.000001);
        $this->assertSame('USD', $log->provider_currency);

        // The message carries the same numbers, so "what did this reply cost
        // me?" never needs the analytics table.
        $usage = MessageUsage::where('message_id', $turn['assistant']->getKey())->first();
        $this->assertNotNull($usage);
        $this->assertSame(1500, $usage->input_tokens);
        $this->assertEqualsWithDelta(10.0, (float) $usage->credit_cost, 0.000001);

        // The routing decision and the cost it produced are one chain.
        $this->assertSame($turn['assistant']->fresh()->routing_log_id, $log->routing_log_id);
    }

    public function test_usage_is_counted_against_the_key_that_served_it(): void
    {
        Http::fake(['*' => Http::response([
            'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50],
        ])]);

        $conversation = app(ConversationService::class)->start($this->user);
        $turn = app(ChatService::class)->beginTurn($conversation, 'Count me.');
        app(ChatService::class)->complete($turn['assistant']);

        $credential = $this->provider->activeCredential();
        $counter = CredentialUsageCounter::where('credential_id', $credential->getKey())->first();

        $this->assertNotNull($counter);
        $this->assertSame(150, (int) $counter->token_count);
        // One call is one call. Seeding the row and then incrementing it would
        // count the first call of every hour twice.
        $this->assertSame(1, (int) $counter->request_count);

        // WHICH key, never the key. A usage row must be safe to export.
        $log = ApiUsageLog::latest('id')->first();
        $this->assertSame($credential->getKey(), $log->credential_id);
        $this->assertStringNotContainsString('sk-', json_encode($log->toArray()));
    }

    // -- dated prices ---------------------------------------------------------

    public function test_a_price_change_never_rewrites_what_the_past_cost(): void
    {
        $then = now()->subMonth();

        $before = app(UsageRecorder::class)->cost(
            $this->model,
            new UsageMetrics(inputTokens: 1000, outputTokens: 0),
            $then,
        );

        // The owner puts the price up today.
        $this->priceAt(now()->subMinute(), input: 0.050, output: 0.060, credits: 40);
        $this->model->load('prices');

        $after = app(UsageRecorder::class)->cost(
            $this->model,
            new UsageMetrics(inputTokens: 1000, outputTokens: 0),
            $then,
        );

        $this->assertEqualsWithDelta($before['provider_cost'], $after['provider_cost'], 0.0000001);
        $this->assertEqualsWithDelta(0.001, $after['provider_cost'], 0.0000001);

        // And today's call uses today's price.
        $today = app(UsageRecorder::class)->cost($this->model, new UsageMetrics(inputTokens: 1000));
        $this->assertEqualsWithDelta(0.050, $today['provider_cost'], 0.0000001);
    }

    public function test_an_unpriced_model_records_a_visible_zero_rather_than_a_guess(): void
    {
        AiModelPrice::query()->delete();
        $this->model->load('prices');

        $cost = app(UsageRecorder::class)->cost($this->model, new UsageMetrics(inputTokens: 5000, outputTokens: 5000));

        $this->assertSame(0.0, $cost['provider_cost']);
        $this->assertSame(0.0, $cost['credit_cost']);
    }

    // -- exchange rates -------------------------------------------------------

    public function test_a_rate_is_taken_from_the_day_it_applied(): void
    {
        ExchangeRate::create([
            'base_currency' => 'USD', 'quote_currency' => 'INR',
            'rate' => 80, 'effective_on' => now()->subMonths(3)->toDateString(),
        ]);

        ExchangeRate::create([
            'base_currency' => 'USD', 'quote_currency' => 'INR',
            'rate' => 90, 'effective_on' => now()->subDays(2)->toDateString(),
        ]);

        $this->assertSame(80.0, ExchangeRate::rateOn('USD', 'INR', now()->subMonth()));
        $this->assertSame(90.0, ExchangeRate::rateOn('USD', 'INR', now()));

        // A gap is covered by the last known figure. A stale rate beats a
        // missing one, which would silently drop a day from a margin report.
        $this->assertSame(90.0, ExchangeRate::rateOn('USD', 'INR', now()->addYear()));

        // The inverse of a stored pair is still a known fact.
        $this->assertEqualsWithDelta(1 / 90, ExchangeRate::rateOn('INR', 'USD', now()), 0.000001);

        // Nothing known is null, never an invented 1.0.
        $this->assertNull(ExchangeRate::rateOn('USD', 'EUR', now()));
    }

    public function test_the_rate_refresh_leaves_the_last_known_rate_alone_when_the_feed_fails(): void
    {
        settings()->set('billing.base_currency', 'INR');
        settings()->set('billing.exchange_rate_source', 'https://rates.test/latest?base={base}');

        ExchangeRate::create([
            'base_currency' => 'INR', 'quote_currency' => 'USD',
            'rate' => 0.012, 'effective_on' => now()->subDay()->toDateString(),
        ]);

        ApiUsageLog::create([
            'provider_id' => $this->provider->getKey(),
            'model_id' => $this->model->getKey(),
            'provider_currency' => 'USD',
            'occurred_at' => now(),
        ]);

        Http::fake(['rates.test/*' => Http::response('nope', 503)]);

        app(RefreshExchangeRatesJob::class)->handle();

        // Unchanged. A feed being down must never make yesterday unreportable.
        $this->assertSame(1, ExchangeRate::count());
        $this->assertEqualsWithDelta(0.012, ExchangeRate::rateOn('INR', 'USD', now()), 0.000001);
    }

    public function test_the_rate_refresh_only_stores_currencies_actually_billed_in(): void
    {
        settings()->set('billing.base_currency', 'INR');
        settings()->set('billing.exchange_rate_source', 'https://rates.test/latest?base={base}');

        ApiUsageLog::create([
            'provider_id' => $this->provider->getKey(),
            'model_id' => $this->model->getKey(),
            'provider_currency' => 'USD',
            'occurred_at' => now(),
        ]);

        Http::fake(['rates.test/*' => Http::response(['rates' => ['USD' => 0.011, 'EUR' => 0.010, 'JPY' => 1.7]])]);

        app(RefreshExchangeRatesJob::class)->handle();

        $this->assertSame(['USD'], ExchangeRate::pluck('quote_currency')->all());
    }

    // -- the nightly rollup ---------------------------------------------------

    public function test_the_rollup_freezes_the_conversion_at_the_days_rate(): void
    {
        settings()->set('billing.base_currency', 'INR');

        $day = now()->subDay()->startOfDay();

        ExchangeRate::create([
            'base_currency' => 'USD', 'quote_currency' => 'INR',
            'rate' => 80, 'effective_on' => $day->toDateString(),
        ]);

        // TODAY'S RATE ALREADY EXISTS AND IS DIFFERENT. Without this the test
        // cannot tell the two behaviours apart: with only one rate on file,
        // converting at the wrong date gives the right answer by accident.
        ExchangeRate::create([
            'base_currency' => 'USD', 'quote_currency' => 'INR',
            'rate' => 95, 'effective_on' => now()->toDateString(),
        ]);

        foreach ([[1000, 500, 0.10, 6.0, null], [2000, 100, 0.20, 9.0, 'timeout']] as [$in, $out, $cost, $credits, $error]) {
            ApiUsageLog::create([
                'user_id' => $this->user->getKey(),
                'provider_id' => $this->provider->getKey(),
                'model_id' => $this->model->getKey(),
                'input_tokens' => $in,
                'output_tokens' => $out,
                'total_tokens' => $in + $out,
                'provider_cost' => $cost,
                'provider_currency' => 'USD',
                'credit_cost' => $credits,
                'latency_ms' => 1200,
                'error_class' => $error,
                'occurred_at' => $day->copy()->addHours(9),
            ]);
        }

        app(AggregateDailyUsageJob::class)->handle();

        $summary = UsageDailySummary::first();

        $this->assertNotNull($summary);
        $this->assertSame(2, $summary->requests);
        $this->assertSame(1, $summary->failures);
        $this->assertSame(3000, (int) $summary->input_tokens);
        $this->assertEqualsWithDelta(0.30, (float) $summary->provider_cost, 0.000001);
        // 0.30 USD at the rate that applied THAT DAY — 24, not the 28.5 that
        // today's rate would give.
        $this->assertEqualsWithDelta(24.0, (float) $summary->provider_cost_base, 0.000001);
        $this->assertEqualsWithDelta(15.0, (float) $summary->credit_cost, 0.000001);

        // Margin is a stored fact, not a figure recomputed every time the
        // rupee moves.
        $this->assertEqualsWithDelta(-9.0, $summary->margin(), 0.000001);
    }

    public function test_running_the_rollup_twice_does_not_double_a_day(): void
    {
        $day = now()->subDay()->startOfDay();

        ApiUsageLog::create([
            'provider_id' => $this->provider->getKey(),
            'model_id' => $this->model->getKey(),
            'input_tokens' => 100,
            'provider_cost' => 0.05,
            'provider_currency' => 'USD',
            'credit_cost' => 2,
            'occurred_at' => $day->copy()->addHours(3),
        ]);

        app(AggregateDailyUsageJob::class)->handle();
        app(AggregateDailyUsageJob::class)->handle();

        $this->assertSame(1, UsageDailySummary::count());
        $this->assertSame(1, UsageDailySummary::first()->requests);
    }

    public function test_the_rollup_records_a_zero_rather_than_inventing_a_missing_rate(): void
    {
        settings()->set('billing.base_currency', 'INR');

        ApiUsageLog::create([
            'provider_id' => $this->provider->getKey(),
            'model_id' => $this->model->getKey(),
            'provider_cost' => 5,
            'provider_currency' => 'USD',
            'credit_cost' => 1,
            'occurred_at' => now()->subDay()->startOfDay()->addHour(),
        ]);

        app(AggregateDailyUsageJob::class)->handle();

        $summary = UsageDailySummary::first();

        // Visibly zero, so an owner can find it, add the rate and re-run.
        $this->assertEqualsWithDelta(5.0, (float) $summary->provider_cost, 0.000001);
        $this->assertEqualsWithDelta(0.0, (float) $summary->provider_cost_base, 0.000001);
    }

    public function test_detailed_rows_are_pruned_while_the_daily_totals_survive(): void
    {
        settings()->set('billing.usage_retention_days', 30);

        ApiUsageLog::create([
            'provider_id' => $this->provider->getKey(),
            'model_id' => $this->model->getKey(),
            'provider_cost' => 1,
            'credit_cost' => 3,
            'occurred_at' => now()->subDays(400),
        ]);

        UsageDailySummary::create([
            'summary_date' => now()->subDays(400)->toDateString(),
            'provider_id' => $this->provider->getKey(),
            'model_id' => $this->model->getKey(),
            'requests' => 1,
        ]);

        app(AggregateDailyUsageJob::class)->handle();

        $this->assertSame(0, ApiUsageLog::count());
        // Long-range reporting survives the pruning that keeps a shared host
        // from filling up.
        $this->assertSame(1, UsageDailySummary::count());
    }

    // -- budgets --------------------------------------------------------------

    public function test_spending_past_a_threshold_alerts_once_per_period(): void
    {
        $budget = AiProviderBudget::create([
            'provider_id' => $this->provider->getKey(),
            'period' => 'monthly',
            'budget_amount' => 10,
            'spent_amount' => 0,
            'currency' => 'USD',
            'threshold_percent' => 80,
            'action_on_breach' => 'warn',
            'period_started_at' => now()->startOfMonth(),
        ]);

        $guard = app(BudgetGuard::class);

        $guard->spend($this->provider->fresh(), 8.5, 'USD');
        $this->assertSame(1, AiProviderBudgetAlert::where('budget_id', $budget->getKey())->count());

        // Alerting on every request past 80% would bury the one message that
        // matters under hundreds that do not.
        $guard->spend($this->provider->fresh(), 0.5, 'USD');
        $this->assertSame(1, AiProviderBudgetAlert::where('threshold_hit', 80)->count());

        $guard->spend($this->provider->fresh(), 2.0, 'USD');
        $this->assertSame(1, AiProviderBudgetAlert::where('threshold_hit', 100)->count());
        $this->assertSame('warn', AiProviderBudgetAlert::where('threshold_hit', 100)->value('action_taken'));
    }

    public function test_a_blocking_budget_stops_the_provider_and_a_warning_one_does_not(): void
    {
        $budget = AiProviderBudget::create([
            'provider_id' => $this->provider->getKey(),
            'period' => 'daily',
            'budget_amount' => 1,
            'spent_amount' => 0,
            'currency' => 'USD',
            'threshold_percent' => 90,
            'action_on_breach' => 'warn',
            'period_started_at' => now()->startOfDay(),
        ]);

        $guard = app(BudgetGuard::class);
        $guard->spend($this->provider->fresh(), 2.0, 'USD');

        $this->assertFalse($guard->blocks($this->provider->fresh()));

        $budget->forceFill(['action_on_breach' => 'block'])->save();

        $this->assertTrue($guard->blocks($this->provider->fresh()));
    }

    public function test_a_new_period_starts_on_its_own(): void
    {
        $budget = AiProviderBudget::create([
            'provider_id' => $this->provider->getKey(),
            'period' => 'daily',
            'budget_amount' => 5,
            'spent_amount' => 5,
            'currency' => 'USD',
            'threshold_percent' => 80,
            'action_on_breach' => 'block',
            'period_started_at' => now()->subDays(2)->startOfDay(),
        ]);

        // A cap that had to be reset by hand every morning is a cap that stops
        // working the first weekend.
        $this->assertFalse(app(BudgetGuard::class)->blocks($this->provider->fresh()));
        $this->assertEqualsWithDelta(0.0, (float) $budget->fresh()->spent_amount, 0.0001);
    }

    public function test_a_budget_in_another_currency_is_not_silently_converted(): void
    {
        $budget = AiProviderBudget::create([
            'provider_id' => $this->provider->getKey(),
            'period' => 'monthly',
            'budget_amount' => 1000,
            'spent_amount' => 0,
            'currency' => 'INR',
            'threshold_percent' => 80,
            'action_on_breach' => 'warn',
            'period_started_at' => now()->startOfMonth(),
        ]);

        app(BudgetGuard::class)->spend($this->provider->fresh(), 5.0, 'USD');

        // Converting here would bake one day's rate into a running total that
        // spans months.
        $this->assertEqualsWithDelta(0.0, (float) $budget->fresh()->spent_amount, 0.0001);
    }
}
