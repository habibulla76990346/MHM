<?php

namespace Tests\Feature\AI;

use App\Domains\AI\Adapters\OpenAiAdapter;
use App\Domains\AI\Models\AiModel;
use App\Domains\AI\Models\AiModelCapability;
use App\Domains\AI\Models\AiModelPrice;
use App\Domains\AI\Models\AiProvider;
use App\Domains\AI\Models\AiProviderBudget;
use App\Domains\AI\Models\AiProviderCredential;
use App\Domains\AI\Models\ProviderHealthLog;
use App\Domains\AI\Models\RoutingLog;
use App\Domains\AI\Routing\AiRouter;
use App\Domains\AI\Routing\CircuitBreaker;
use App\Domains\AI\Routing\RejectionReason;
use App\Domains\AI\Routing\RoutingMode;
use App\Domains\AI\Support\Capability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Phase 5 gate, first half: every routing mode selects what §14 says it
 * should, and every model that was not chosen has a recorded reason.
 *
 * Three providers with deliberately opposed strengths, so no single model can
 * win every mode by accident — a fixture where one model is best at everything
 * would make all eight assertions pass without the modes doing anything.
 */
class RoutingModeTest extends TestCase
{
    use RefreshDatabase;

    private AiModel $premium;    // best quality, expensive, slow, priority 3

    private AiModel $budget;     // cheapest, mediocre quality, priority 2

    private AiModel $quick;      // fastest, free tier, priority 1

    protected function setUp(): void
    {
        parent::setUp();

        $this->premium = $this->model('Premium', priority: 3, quality: 95, inputCost: 0.900, latencyMs: 6000);
        $this->budget = $this->model('Budget', priority: 2, quality: 40, inputCost: 0.010, latencyMs: 2500);
        $this->quick = $this->model('Quick', priority: 1, quality: 60, inputCost: 0.300, latencyMs: 200, accountClass: 'free');
    }

    private function model(
        string $name,
        int $priority,
        int $quality,
        float $inputCost,
        int $latencyMs,
        string $accountClass = 'paid',
        array $capabilities = [Capability::CHAT, Capability::STREAMING],
    ): AiModel {
        $provider = AiProvider::create([
            'name' => $name,
            'adapter_type' => OpenAiAdapter::KEY,
            'api_base_url' => 'https://'.strtolower($name).'.test/v1',
            'status' => AiProvider::STATUS_ACTIVE,
            'priority' => $priority,
            'account_class' => $accountClass,
        ]);

        AiProviderCredential::create([
            'provider_id' => $provider->getKey(),
            'label' => 'Key',
            'credential' => 'sk-'.strtolower($name).'-KEYKEYKEY1234',
        ]);

        $model = AiModel::create([
            'provider_id' => $provider->getKey(),
            'model_identifier' => strtolower($name).'-1',
            'display_name' => $name.' One',
            'is_enabled' => true,
            'quality_rank' => $quality,
            'context_window' => 32000,
        ]);

        AiModelCapability::syncForModel($model, $capabilities);

        AiModelPrice::create([
            'model_id' => $model->getKey(),
            'unit' => 'per_1k_input',
            'provider_cost' => $inputCost,
            'credit_cost' => $inputCost * 2,
            'currency' => 'USD',
            'effective_from' => now()->subYear(),
        ]);

        AiModelPrice::create([
            'model_id' => $model->getKey(),
            'unit' => 'per_1k_output',
            'provider_cost' => $inputCost * 3,
            'credit_cost' => $inputCost * 6,
            'currency' => 'USD',
            'effective_from' => now()->subYear(),
        ]);

        // Health comes from REAL traffic, so the fixture supplies real rows
        // rather than stubbing the service — that is the thing being proven.
        for ($i = 0; $i < 5; $i++) {
            ProviderHealthLog::create([
                'provider_id' => $provider->getKey(),
                'model_id' => $model->getKey(),
                'checked_at' => now()->subMinutes($i + 1),
                'success' => true,
                'latency_ms' => $latencyMs,
                'http_status' => 200,
            ]);
        }

        return $model->fresh('provider');
    }

    private function route(string $mode, array $required = [Capability::CHAT], ...$args)
    {
        return app(AiRouter::class)->route($required, $mode, ...$args);
    }

    // -- the eight modes ------------------------------------------------------

    public function test_best_quality_picks_the_highest_ranked_model(): void
    {
        $decision = $this->route(RoutingMode::BEST_QUALITY);

        $this->assertSame($this->premium->getKey(), $decision->model?->getKey());
    }

    public function test_fastest_picks_the_lowest_observed_latency(): void
    {
        // Not the advertised figure — the median of what customers actually
        // experienced on this server.
        $decision = $this->route(RoutingMode::FASTEST);

        $this->assertSame($this->quick->getKey(), $decision->model?->getKey());
    }

    public function test_lowest_cost_picks_the_cheapest_model_that_can_do_the_job(): void
    {
        $decision = $this->route(RoutingMode::LOWEST_COST);

        $this->assertSame($this->budget->getKey(), $decision->model?->getKey());
    }

    public function test_free_only_never_leaves_the_free_tier(): void
    {
        $decision = $this->route(RoutingMode::FREE_ONLY);

        $this->assertSame($this->quick->getKey(), $decision->model?->getKey());

        // And the paid providers were rejected FOR THAT REASON, not silently.
        $rejections = collect($decision->candidates)
            ->filter(fn ($c) => ! $c->eligible)
            ->pluck('rejection');

        $this->assertTrue($rejections->contains(RejectionReason::NOT_FREE_TIER));
    }

    public function test_admin_preferred_follows_the_owners_order_only(): void
    {
        // Priority 1 wins even though it is neither the best nor the cheapest.
        $decision = $this->route(RoutingMode::ADMIN_PREFERRED);

        $this->assertSame($this->quick->getKey(), $decision->model?->getKey());
    }

    public function test_specific_provider_never_leaves_that_provider(): void
    {
        $decision = $this->route(
            RoutingMode::SPECIFIC_PROVIDER,
            [Capability::CHAT],
            null,
            $this->premium->provider_id,
        );

        $this->assertSame($this->premium->getKey(), $decision->model?->getKey());
    }

    public function test_specific_model_selects_exactly_that_model(): void
    {
        $decision = $this->route(
            RoutingMode::SPECIFIC_MODEL,
            [Capability::CHAT],
            null,
            null,
            $this->budget->getKey(),
        );

        $this->assertSame($this->budget->getKey(), $decision->model?->getKey());
    }

    public function test_auto_balances_rather_than_maximising_any_one_factor(): void
    {
        $decision = $this->route(RoutingMode::AUTO);

        // Auto must not simply be one of the single-factor modes wearing a
        // different name: the slowest, most expensive model never wins it when
        // health is equal across providers.
        $this->assertNotSame($this->premium->getKey(), $decision->model?->getKey());
        $this->assertNotNull($decision->model);
    }

    public function test_an_unknown_mode_falls_back_to_automatic_rather_than_failing(): void
    {
        $decision = $this->route('something_an_upgrade_removed');

        $this->assertSame(RoutingMode::AUTO, $decision->mode);
        $this->assertNotNull($decision->model);
    }

    // -- the hard filters -----------------------------------------------------

    public function test_a_vision_request_only_ever_considers_models_that_can_see(): void
    {
        // THE GUARANTEE §14 CALLS OUT BY NAME. A text-only model answering a
        // question about a picture does not error — it answers wrongly, which
        // is far worse.
        $seeing = $this->model('Seeing', priority: 9, quality: 10, inputCost: 5.0, latencyMs: 9000,
            capabilities: [Capability::CHAT, Capability::VISION]);

        $decision = $this->route(RoutingMode::AUTO, [Capability::CHAT, Capability::VISION]);

        $this->assertSame($seeing->getKey(), $decision->model?->getKey());

        foreach ($decision->candidates as $candidate) {
            if ($candidate->eligible) {
                $this->assertTrue($candidate->model->supports(Capability::VISION));
            }
        }
    }

    public function test_a_conversation_too_big_for_a_context_window_rejects_that_model(): void
    {
        $this->premium->update(['context_window' => 4000]);

        $decision = $this->route(RoutingMode::BEST_QUALITY, [Capability::CHAT], null, null, null, 30000);

        $this->assertNotSame($this->premium->getKey(), $decision->model?->getKey());

        $rejected = collect($decision->candidates)->firstWhere('model.id', $this->premium->getKey());
        $this->assertSame(RejectionReason::CONTEXT_TOO_SMALL, $rejected->rejection);
    }

    public function test_a_provider_over_a_blocking_budget_is_not_considered(): void
    {
        AiProviderBudget::create([
            'provider_id' => $this->premium->provider_id,
            'period' => 'monthly',
            'budget_amount' => 10,
            'spent_amount' => 12,
            'currency' => 'USD',
            'threshold_percent' => 80,
            'action_on_breach' => 'block',
            'period_started_at' => now()->startOfMonth(),
        ]);

        $decision = $this->route(RoutingMode::BEST_QUALITY);

        $this->assertNotSame($this->premium->getKey(), $decision->model?->getKey());
    }

    public function test_a_warning_budget_does_not_stop_a_provider_being_used(): void
    {
        // "Warn" means the owner wants to KNOW, not to take their product down.
        AiProviderBudget::create([
            'provider_id' => $this->premium->provider_id,
            'period' => 'monthly',
            'budget_amount' => 10,
            'spent_amount' => 12,
            'currency' => 'USD',
            'threshold_percent' => 80,
            'action_on_breach' => 'warn',
            'period_started_at' => now()->startOfMonth(),
        ]);

        $decision = $this->route(RoutingMode::BEST_QUALITY);

        $this->assertSame($this->premium->getKey(), $decision->model?->getKey());
    }

    public function test_a_provider_with_an_open_circuit_is_routed_around(): void
    {
        app(CircuitBreaker::class)->forceOpen($this->premium->provider);

        $decision = $this->route(RoutingMode::BEST_QUALITY);

        $this->assertNotSame($this->premium->getKey(), $decision->model?->getKey());
        $this->assertNotNull($decision->model);
    }

    // -- the log --------------------------------------------------------------

    public function test_every_decision_records_every_candidate_and_why(): void
    {
        $this->premium->update(['is_enabled' => false]);

        $decision = $this->route(RoutingMode::AUTO);

        $log = RoutingLog::latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame($decision->model?->getKey(), $log->selected_model_id);
        // Three models considered, not just the survivors: "why was this model
        // NOT used?" is the question the log exists to answer.
        $this->assertCount(3, $log->candidates);

        $rejected = $log->rejectedCandidates();
        $this->assertCount(1, $rejected);
        $this->assertSame(RejectionReason::MODEL_DISABLED, $rejected[0]['rejection_code']);
        // In words, so an owner never has to look a code up.
        $this->assertNotEmpty($rejected[0]['rejected_because']);
    }

    public function test_nothing_available_explains_itself_from_the_actual_rejections(): void
    {
        AiModel::query()->update(['is_enabled' => false]);

        $decision = $this->route(RoutingMode::AUTO);

        $this->assertNull($decision->model);
        $this->assertSame(RejectionReason::MODEL_DISABLED, $decision->dominantRejection());
        $this->assertStringContainsString('not available to customers', $decision->explainFailure());
    }

    public function test_a_routing_log_never_contains_a_credential(): void
    {
        $this->route(RoutingMode::AUTO);

        $log = RoutingLog::latest('id')->first();
        $encoded = json_encode($log->toArray());

        $this->assertStringNotContainsString('sk-', $encoded);
        $this->assertStringNotContainsString('KEYKEYKEY', $encoded);
    }
}
