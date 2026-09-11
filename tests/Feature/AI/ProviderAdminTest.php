<?php

namespace Tests\Feature\AI;

use App\Domains\AI\Adapters\OpenAiCompatibleAdapter;
use App\Domains\AI\Models\AiModel;
use App\Domains\AI\Models\AiProvider;
use App\Domains\AI\Models\AiProviderCredential;
use App\Domains\AI\Models\ProviderCircuitState;
use App\Domains\AI\Models\ProviderHealthLog;
use App\Domains\Security\Models\ActivityLog;
use App\Domains\Security\Services\PermissionRegistry;
use App\Filament\Pages\AiTestConsole;
use App\Filament\Resources\AiModels\AiModelResource;
use App\Filament\Resources\AiModels\Pages\CreateAiModel;
use App\Filament\Resources\AiModels\Pages\ListAiModels;
use App\Filament\Resources\AiProviders\Pages\CreateAiProvider;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Phase 3 admin gate: "provider added via panel · test connection returns
 * real status and latency" — plus the §25 test console.
 */
class ProviderAdminTest extends TestCase
{
    use RefreshDatabase;

    private AiProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->provider = AiProvider::create([
            'name' => 'Panel Provider',
            'adapter_type' => OpenAiCompatibleAdapter::KEY,
            'api_base_url' => 'https://api.panel.test/v1',
            'status' => AiProvider::STATUS_ACTIVE,
        ]);

        AiProviderCredential::create([
            'provider_id' => $this->provider->getKey(),
            'label' => 'Key',
            'credential' => 'sk-panel-KEYKEYKEYKEY4321',
        ]);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user->fresh();
    }

    private function admin(): User
    {
        return $this->userWithRole(PermissionRegistry::SUPER_ADMIN);
    }

    // -- access --------------------------------------------------------------

    public function test_the_ai_screens_are_permission_gated(): void
    {
        $customer = $this->userWithRole(PermissionRegistry::CUSTOMER);

        foreach (['/admin/ai-providers', '/admin/ai-models', '/admin/ai-test-console'] as $url) {
            $this->actingAs($customer)->get($url)->assertForbidden();
        }

        foreach (['/admin/ai-providers', '/admin/ai-models', '/admin/ai-test-console'] as $url) {
            $this->actingAs($this->admin())->get($url)->assertOk();
        }
    }

    /**
     * §9: a support role must not automatically gain access to API
     * credentials. It can see that a provider exists; it cannot change it.
     */
    public function test_support_can_look_but_not_touch(): void
    {
        $support = $this->userWithRole(PermissionRegistry::SUPPORT_MANAGER);

        $this->assertTrue($support->can('providers.view'));
        $this->assertFalse($support->can('providers.manage'));
        $this->assertFalse($support->can('credentials.view'));
        $this->assertFalse($support->can('credentials.manage'));

        $this->actingAs($support)->get('/admin/ai-providers')->assertOk();
    }

    // -- adding a provider through the panel ---------------------------------

    public function test_a_provider_can_be_added_entirely_through_the_panel(): void
    {
        Livewire::actingAs($this->admin())
            ->test(CreateAiProvider::class)
            ->fillForm([
                'name' => 'Some New Provider',
                'slug' => 'some-new-provider',
                'adapter_type' => OpenAiCompatibleAdapter::KEY,
                'api_base_url' => 'https://api.new.test/v1',
                'auth_method' => 'bearer',
                'status' => AiProvider::STATUS_DISABLED,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $created = AiProvider::where('slug', 'some-new-provider')->first();

        $this->assertNotNull($created);
        // A new provider starts disabled: test it first, then switch it on.
        $this->assertSame(AiProvider::STATUS_DISABLED, $created->status);
    }

    // -- the test console (§25) ----------------------------------------------

    public function test_the_console_reports_real_status_and_latency(): void
    {
        Http::fake(['*/models' => Http::response(['data' => [['id' => 'a'], ['id' => 'b']]])]);

        Livewire::actingAs($this->admin())
            ->test(AiTestConsole::class)
            ->set('providerId', $this->provider->getKey())
            ->call('testCredential')
            ->assertSet('result.success', true)
            ->assertSet('result.http_status', 200)
            ->assertSet('result.models_visible', 2);

        // The exchange is recorded as health history.
        $log = ProviderHealthLog::latest('id')->first();
        $this->assertTrue($log->success);
        $this->assertNull($log->error_class);
    }

    public function test_the_console_reports_a_failure_as_a_class_and_a_remedy(): void
    {
        Http::fake(['*/models' => Http::response([
            'error' => ['message' => 'Invalid key sk-panel-KEYKEYKEYKEY4321'],
        ], 401)]);

        $component = Livewire::actingAs($this->admin())
            ->test(AiTestConsole::class)
            ->set('providerId', $this->provider->getKey())
            ->call('testCredential')
            ->assertSet('result.success', false)
            ->assertSet('result.error_class', 'authentication');

        // The provider's own words never reach the screen.
        $component->assertDontSee('sk-panel');
        $component->assertDontSee('Invalid key sk-panel');

        $this->assertStringContainsString(
            'API key',
            $component->get('result')['detail'],
        );
    }

    /**
     * A test must never become expensive: the request is capped at a handful
     * of output tokens and uses a fixed prompt.
     */
    public function test_a_test_request_is_bounded(): void
    {
        $model = AiModel::create([
            'provider_id' => $this->provider->getKey(),
            'model_identifier' => 'panel-model',
            'display_name' => 'Panel Model',
            'is_enabled' => true,
        ]);

        Http::fake(['*/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'ready'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 6, 'completion_tokens' => 1],
        ])]);

        Livewire::actingAs($this->admin())
            ->test(AiTestConsole::class)
            ->set('providerId', $this->provider->getKey())
            ->set('modelId', $model->getKey())
            ->call('sendTestRequest')
            ->assertSet('result.success', true)
            ->assertSet('result.output_tokens', 1);

        Http::assertSent(fn ($request) => $request->data()['max_tokens'] === AiTestConsole::MAX_OUTPUT_TOKENS);
    }

    public function test_a_test_request_estimates_cost_from_the_dated_price(): void
    {
        $model = AiModel::create([
            'provider_id' => $this->provider->getKey(),
            'model_identifier' => 'priced-model',
            'display_name' => 'Priced',
            'is_enabled' => true,
        ]);

        $model->prices()->create([
            'unit' => 'per_1k_input', 'provider_cost' => 0.01, 'credit_cost' => 0.02,
            'currency' => 'USD', 'effective_from' => now()->subDay(),
        ]);
        $model->prices()->create([
            'unit' => 'per_1k_output', 'provider_cost' => 0.03, 'credit_cost' => 0.06,
            'currency' => 'USD', 'effective_from' => now()->subDay(),
        ]);

        Http::fake(['*/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'ready']]],
            'usage' => ['prompt_tokens' => 1000, 'completion_tokens' => 1000],
        ])]);

        $result = Livewire::actingAs($this->admin())
            ->test(AiTestConsole::class)
            ->set('providerId', $this->provider->getKey())
            ->set('modelId', $model->getKey())
            ->call('sendTestRequest')
            ->get('result');

        // 1k in at 0.01 + 1k out at 0.03.
        $this->assertStringStartsWith('0.040000', $result['estimated_cost']);
    }

    public function test_an_unpriced_model_says_so_rather_than_inventing_a_number(): void
    {
        $model = AiModel::create([
            'provider_id' => $this->provider->getKey(),
            'model_identifier' => 'unpriced',
            'display_name' => 'Unpriced',
            'is_enabled' => true,
        ]);

        Http::fake(['*/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'ready']]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 2],
        ])]);

        $result = Livewire::actingAs($this->admin())
            ->test(AiTestConsole::class)
            ->set('providerId', $this->provider->getKey())
            ->set('modelId', $model->getKey())
            ->call('sendTestRequest')
            ->get('result');

        $this->assertNull($result['estimated_cost']);
    }

    public function test_the_console_shows_the_key_only_as_its_last_four(): void
    {
        $console = Livewire::actingAs($this->admin())
            ->test(AiTestConsole::class)
            ->set('providerId', $this->provider->getKey());

        $this->assertSame('••••••••4321', $console->instance()->credentialHint());
        $console->assertDontSee('sk-panel-KEYKEYKEYKEY4321');
    }

    public function test_resetting_the_circuit_breaker_is_audited(): void
    {
        ProviderCircuitState::create([
            'provider_id' => $this->provider->getKey(),
            'state' => ProviderCircuitState::OPEN,
            'failure_count' => 9,
            'opened_at' => now(),
        ]);

        $this->assertTrue($this->provider->fresh()->circuit->isOpen());

        Livewire::actingAs($this->admin())
            ->test(AiTestConsole::class)
            ->set('providerId', $this->provider->getKey())
            ->call('resetCircuit');

        $circuit = $this->provider->fresh()->circuit;

        $this->assertFalse($circuit->isOpen());
        $this->assertSame(0, $circuit->failure_count);
        $this->assertDatabaseHas('activity_logs', ['action' => 'provider.circuit_reset']);
    }

    public function test_a_forced_open_circuit_stays_open_until_someone_resets_it(): void
    {
        // §24 emergency control: pull a provider out at 2am without disabling
        // it permanently.
        $circuit = ProviderCircuitState::create([
            'provider_id' => $this->provider->getKey(),
            'state' => ProviderCircuitState::CLOSED,
            'forced_open' => true,
        ]);

        $this->assertTrue($circuit->isOpen());
        $this->assertStringContainsString('Forced open', $circuit->stateLabel());
    }

    // -- catalog screens -----------------------------------------------------

    /**
     * A synced model is never deletable: usage records refer to it, and the
     * next sync would bring it straight back. Deprecating is the right action.
     */
    public function test_a_synced_model_cannot_be_deleted_but_a_manual_one_can(): void
    {
        $this->actingAs($this->admin());

        $synced = AiModel::create([
            'provider_id' => $this->provider->getKey(),
            'model_identifier' => 'from-sync', 'display_name' => 'Synced',
            'source' => AiModel::SOURCE_SYNCED,
        ]);

        $manual = AiModel::create([
            'provider_id' => $this->provider->getKey(),
            'model_identifier' => 'by-hand', 'display_name' => 'Manual',
            'source' => AiModel::SOURCE_MANUAL,
        ]);

        $this->assertFalse(AiModelResource::canDelete($synced));
        $this->assertTrue(AiModelResource::canDelete($manual));
    }

    public function test_enabling_a_model_from_the_list_is_audited(): void
    {
        $model = AiModel::create([
            'provider_id' => $this->provider->getKey(),
            'model_identifier' => 'toggle-me', 'display_name' => 'Toggle', 'is_enabled' => false,
        ]);

        Livewire::actingAs($this->admin())
            ->test(ListAiModels::class)
            ->call('updateTableColumnState', 'is_enabled', (string) $model->getKey(), true);

        $entry = ActivityLog::where('action', 'model.toggled')->latest('id')->first();

        $this->assertNotNull($entry);
        $this->assertFalse($entry->before['is_enabled']);
        $this->assertTrue($entry->after['is_enabled']);
        $this->assertTrue($model->fresh()->is_enabled);
    }

    public function test_a_manually_created_model_is_marked_manual_so_a_sync_leaves_it_alone(): void
    {
        Livewire::actingAs($this->admin())
            ->test(CreateAiModel::class)
            ->fillForm([
                'provider_id' => $this->provider->getKey(),
                'model_identifier' => 'typed-in',
                'display_name' => 'Typed In',
                'status' => AiModel::STATUS_STABLE,
                'modality' => 'text',
                'capability_keys' => ['chat', 'vision'],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $model = AiModel::where('model_identifier', 'typed-in')->first();

        $this->assertNotNull($model);
        $this->assertSame(AiModel::SOURCE_MANUAL, $model->source);
        $this->assertTrue($model->supports('chat'));
        $this->assertTrue($model->supports('vision'));
        $this->assertFalse($model->supports('embeddings'));
    }
}
