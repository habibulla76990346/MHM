<?php

namespace Tests\Feature\AI;

use App\Domains\AI\Adapters\CustomHttpAdapter;
use App\Domains\AI\Adapters\OpenAiCompatibleAdapter;
use App\Domains\AI\Models\AiModel;
use App\Domains\AI\Models\AiModelCapability;
use App\Domains\AI\Models\AiModelPrice;
use App\Domains\AI\Models\AiModelSyncLog;
use App\Domains\AI\Models\AiProvider;
use App\Domains\AI\Models\AiProviderCredential;
use App\Domains\AI\Services\ModelSyncService;
use App\Domains\AI\Support\Capability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Phase 3 catalog gate: "model sync populates the catalog · new models
 * arrive DISABLED · deprecated models are NOT DELETED".
 */
class ModelCatalogTest extends TestCase
{
    use RefreshDatabase;

    private AiProvider $provider;

    /**
     * What the fake provider currently lists.
     *
     * Held as state rather than re-registered per call: a second Http::fake()
     * for the same pattern does NOT replace the first — Laravel keeps the
     * first matching stub — so re-faking silently returned the original
     * catalog and made every "the model disappeared" test pass vacuously.
     *
     * @var array<int, string>
     */
    private array $catalog = [];

    /** When set, the catalog endpoint fails instead: [body, status]. */
    private ?array $failWith = null;

    protected function setUp(): void
    {
        parent::setUp();

        // ONE stub, reading mutable state. Registering a second one for the
        // same pattern would never fire.
        Http::fake(['*/models' => function () {
            if ($this->failWith !== null) {
                return Http::response($this->failWith[0], $this->failWith[1]);
            }

            return Http::response([
                'data' => array_map(fn ($id) => is_array($id) ? $id : ['id' => $id], $this->catalog),
            ]);
        }]);

        $this->provider = AiProvider::create([
            'name' => 'Catalog Provider',
            'adapter_type' => OpenAiCompatibleAdapter::KEY,
            'api_base_url' => 'https://api.catalog.test/v1',
            'status' => AiProvider::STATUS_ACTIVE,
        ]);

        AiProviderCredential::create([
            'provider_id' => $this->provider->getKey(),
            'label' => 'Key',
            'credential' => 'sk-catalog-KEYKEYKEY1234',
        ]);
    }

    private function fakeCatalog(array $ids): void
    {
        $this->catalog = $ids;
    }

    private function sync(): AiModelSyncLog
    {
        return app(ModelSyncService::class)->sync($this->provider->fresh());
    }

    public function test_a_sync_populates_the_catalog_and_logs_what_it_did(): void
    {
        $this->fakeCatalog(['model-a', 'model-b', 'model-c']);

        $log = $this->sync();

        $this->assertSame(AiModelSyncLog::STATUS_SUCCESS, $log->status);
        $this->assertSame(3, $log->models_added);
        $this->assertSame(3, AiModel::count());
        $this->assertNotNull($log->finished_at);
        // A digest, never the provider's raw payload.
        $this->assertNotNull($log->raw_response_digest);
    }

    /**
     * THE GATE. Providers add models constantly. If a sync enabled them, a
     * provider's release schedule would decide what an owner's customers can
     * spend money on, without the owner ever seeing it.
     */
    public function test_every_newly_discovered_model_arrives_switched_off(): void
    {
        $this->fakeCatalog(['brand-new-1', 'brand-new-2']);

        $this->sync();

        $this->assertSame(2, AiModel::count());
        $this->assertSame(0, AiModel::where('is_enabled', true)->count());
        $this->assertTrue(AiModel::all()->every(fn (AiModel $m) => $m->is_enabled === false));
        $this->assertTrue(AiModel::all()->every(fn (AiModel $m) => $m->source === AiModel::SOURCE_SYNCED));
    }

    public function test_a_sync_gives_a_new_model_its_default_capabilities(): void
    {
        $this->fakeCatalog(['model-a']);
        $this->sync();

        $model = AiModel::first();

        $this->assertTrue($model->supports(Capability::CHAT));
        $this->assertTrue($model->supports(Capability::STREAMING));
        $this->assertFalse($model->supports(Capability::VISION));
    }

    /**
     * THE GATE. Usage records, invoices and analytics refer to a model.
     * Deleting one would orphan that history and silently change past reports.
     */
    public function test_a_model_the_provider_stops_listing_is_deprecated_never_deleted(): void
    {
        $this->fakeCatalog(['keeper', 'goner']);
        $this->sync();

        $goner = AiModel::where('model_identifier', 'goner')->first();
        $goner->update(['is_enabled' => true]);

        $this->fakeCatalog(['keeper']);
        $log = $this->sync();

        $this->assertSame(1, $log->models_deprecated);

        // Still there.
        $this->assertDatabaseHas('ai_models', ['model_identifier' => 'goner', 'deleted_at' => null]);

        $goner = $goner->fresh();
        $this->assertSame(AiModel::STATUS_DEPRECATED, $goner->status);
        // ... and switched off, so it is never chosen for new work.
        $this->assertFalse($goner->is_enabled);
        $this->assertFalse($goner->isRoutable());
    }

    public function test_a_model_that_comes_back_is_available_again_but_still_switched_off(): void
    {
        $this->fakeCatalog(['flaky']);
        $this->sync();

        $this->fakeCatalog([]);
        $this->sync();
        $this->assertSame(AiModel::STATUS_DEPRECATED, AiModel::first()->status);

        $this->fakeCatalog(['flaky']);
        $this->sync();

        $model = AiModel::first();
        $this->assertSame(AiModel::STATUS_STABLE, $model->status);
        // The owner still decides.
        $this->assertFalse($model->is_enabled);
    }

    /**
     * A manually added model exists precisely because the provider does not
     * list it. A sync must not undo the administrator's work.
     */
    public function test_a_manually_added_model_is_never_deprecated_or_overwritten(): void
    {
        $manual = AiModel::create([
            'provider_id' => $this->provider->getKey(),
            'model_identifier' => 'hand-typed',
            'display_name' => 'My Chosen Name',
            'description' => 'My own description',
            'context_window' => 42000,
            'is_enabled' => true,
            'source' => AiModel::SOURCE_MANUAL,
        ]);

        $this->fakeCatalog(['something-else']);
        $log = $this->sync();

        $manual = $manual->fresh();

        $this->assertSame(0, $log->models_deprecated);
        $this->assertSame(AiModel::STATUS_STABLE, $manual->status);
        $this->assertTrue($manual->is_enabled);
        $this->assertSame('My Chosen Name', $manual->display_name);
        $this->assertSame(42000, $manual->context_window);
    }

    public function test_a_sync_only_fills_gaps_and_never_overwrites_an_edit(): void
    {
        $this->catalog = [['id' => 'model-a', 'context_length' => 8000, 'description' => 'Provider wording']];
        $this->sync();

        $model = AiModel::first();
        $model->update(['display_name' => 'Renamed by the owner', 'context_window' => 9999]);

        $this->sync();

        $model = $model->fresh();
        $this->assertSame('Renamed by the owner', $model->display_name);
        $this->assertSame(9999, $model->context_window);
    }

    public function test_a_failed_sync_is_logged_with_a_remedy_not_a_raw_error(): void
    {
        $this->failWith = [
            ['error' => ['code' => 'invalid_api_key', 'message' => 'Bad key sk-catalog-KEYKEYKEY1234']],
            401,
        ];

        $log = $this->sync();

        $this->assertSame(AiModelSyncLog::STATUS_FAILED, $log->status);
        $this->assertStringNotContainsString('sk-catalog', (string) $log->error_message);
        // A remedy an owner can act on.
        $this->assertStringContainsString('API key', (string) $log->error_message);
        $this->assertSame(0, AiModel::count());
    }

    public function test_a_provider_with_no_model_list_is_reported_as_manual_not_broken(): void
    {
        $custom = AiProvider::create([
            'name' => 'Manual only',
            'adapter_type' => CustomHttpAdapter::KEY,
            'api_base_url' => 'https://api.manual.test',
            'status' => AiProvider::STATUS_ACTIVE,
        ]);

        $sync = app(ModelSyncService::class);

        // Where a provider publishes no list, the catalog is maintained by
        // hand — that is the design, not a fault (Rule 5).
        $this->assertFalse($sync->canSync($custom));

        $log = $sync->sync($custom);

        $this->assertSame(AiModelSyncLog::STATUS_FAILED, $log->status);
        $this->assertStringContainsString('maintained by hand', $log->error_message);
    }

    // -- pricing -------------------------------------------------------------

    /**
     * §13. Prices change. A usage record from March must stay costed at
     * March's price, or editing a price silently rewrites the profitability of
     * the entire history.
     */
    public function test_usage_is_costed_at_the_price_that_applied_at_the_time(): void
    {
        $this->fakeCatalog(['priced']);
        $this->sync();

        $model = AiModel::first();

        AiModelPrice::create([
            'model_id' => $model->getKey(), 'unit' => 'per_1k_input',
            'provider_cost' => 0.001, 'credit_cost' => 0.002,
            'effective_from' => now()->subMonths(3),
            'effective_until' => now()->subMonth(),
        ]);

        AiModelPrice::create([
            'model_id' => $model->getKey(), 'unit' => 'per_1k_input',
            'provider_cost' => 0.005, 'credit_cost' => 0.010,
            'effective_from' => now()->subMonth(),
        ]);

        $then = $model->priceAt('per_1k_input', now()->subMonths(2));
        $now = $model->priceAt('per_1k_input');

        $this->assertSame('0.00100000', $then->provider_cost);
        $this->assertSame('0.00500000', $now->provider_cost);
        $this->assertNotSame($then->id, $now->id);
    }

    public function test_the_margin_is_the_gap_between_cost_and_price(): void
    {
        $this->fakeCatalog(['priced']);
        $this->sync();

        $price = AiModelPrice::create([
            'model_id' => AiModel::first()->getKey(), 'unit' => 'per_1k_output',
            'provider_cost' => 0.002, 'credit_cost' => 0.006,
            'effective_from' => now()->subDay(),
        ]);

        $this->assertEqualsWithDelta(0.004, $price->margin(), 0.0000001);
        $this->assertTrue($price->isCurrent());
    }

    public function test_a_price_with_no_window_covering_the_moment_returns_nothing(): void
    {
        $this->fakeCatalog(['priced']);
        $this->sync();

        $model = AiModel::first();

        AiModelPrice::create([
            'model_id' => $model->getKey(), 'unit' => 'per_1k_input',
            'provider_cost' => 0.001, 'credit_cost' => 0.002,
            'effective_from' => now()->addWeek(),
        ]);

        // Guessing at a price would silently invent revenue.
        $this->assertNull($model->priceAt('per_1k_input'));
    }

    // -- routability ---------------------------------------------------------

    public function test_only_enabled_models_on_usable_providers_are_routable(): void
    {
        $this->fakeCatalog(['a', 'b', 'c']);
        $this->sync();

        AiModel::query()->update(['is_enabled' => true]);
        $this->assertSame(3, AiModel::routable()->count());

        AiModel::where('model_identifier', 'a')->update(['status' => AiModel::STATUS_DEPRECATED]);
        AiModel::where('model_identifier', 'b')->update(['is_enabled' => false]);

        $this->assertSame(1, AiModel::routable()->count());

        // Maintenance mode takes every model with it, without losing settings.
        $this->provider->update(['maintenance_mode' => true]);
        $this->assertSame(0, AiModel::routable()->count());
    }

    public function test_models_are_selected_by_capability_never_by_name(): void
    {
        $this->fakeCatalog(['plain', 'seeing']);
        $this->sync();

        AiModel::query()->update(['is_enabled' => true]);

        $seeing = AiModel::where('model_identifier', 'seeing')->first();
        AiModelCapability::syncForModel($seeing, [
            Capability::CHAT, Capability::STREAMING, Capability::VISION,
        ]);

        $withVision = AiModel::routable()->withCapabilities([Capability::VISION])->get();

        $this->assertCount(1, $withVision);
        $this->assertSame('seeing', $withVision->first()->model_identifier);
    }
}
