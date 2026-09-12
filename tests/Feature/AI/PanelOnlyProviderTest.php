<?php

namespace Tests\Feature\AI;

use App\Domains\AI\Adapters\OpenAiCompatibleAdapter;
use App\Domains\AI\Models\AiProvider;
use App\Domains\AI\Models\AiProviderCredential;
use App\Domains\AI\Services\ProviderRegistry;
use App\Domains\Chat\Models\Message;
use App\Domains\Chat\Services\ChatService;
use App\Domains\Chat\Services\ConversationService;
use App\Domains\Security\Models\ActivityLog;
use App\Domains\Security\Services\PermissionRegistry;
use App\Filament\Resources\AiModels\Pages\ListAiModels;
use App\Filament\Resources\AiProviders\Pages\CreateAiProvider;
use App\Filament\Resources\AiProviders\Pages\EditAiProvider;
use App\Filament\Resources\AiProviders\Pages\ListAiProviders;
use App\Filament\Resources\AiProviders\RelationManagers\CredentialsRelationManager;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The last Phase 7 gate: **a provider Aziv AI has never heard of can be put
 * into service entirely from the Admin Panel, with no code change.**
 *
 * This is the claim §12 makes and the reason the adapter layer exists at all.
 * It is also the easiest claim in the plan to believe without checking — every
 * provider added so far was added by someone who could also edit the source.
 *
 * So the provider used here is invented. Its name, its slug and its hostname
 * appear in this file and nowhere else in the product, and the last test
 * proves that by reading `app/` and failing if any of them turns up. If a
 * future change ever needs a line of code to make this provider work, that
 * line will contain one of these words and this suite will say so.
 *
 * The journey below is the one an owner actually walks: add the provider,
 * paste a key, check the connection, refresh the catalog, switch a model on,
 * chat. Every step goes through the real Filament screen, not through the
 * model underneath it.
 */
class PanelOnlyProviderTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A company that does not exist.
     *
     * `.test` is reserved by RFC 6761 and can never resolve, so a stub that
     * failed to intercept would fail loudly rather than reach anybody.
     */
    private const NAME = 'Northwind Intelligence';

    private const SLUG = 'northwind-intelligence';

    private const HOST = 'api.northwind-intelligence.test';

    /** The token the code scan looks for. */
    private const TOKEN = 'northwind';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(PermissionRegistry::SUPER_ADMIN);
        $this->admin = $this->admin->fresh();

        settings()->set('routing.max_retries', 0);

        // One stub for the whole invented API. It answers in the shape most of
        // the market copies, which is the entire reason no adapter is needed.
        Http::fake([
            '*'.self::HOST.'/v1/models' => Http::response(['data' => [
                ['id' => 'northwind-large', 'context_length' => 128000],
                ['id' => 'northwind-small', 'context_length' => 32000],
            ]]),
            '*'.self::HOST.'/v1/chat/completions' => Http::response([
                'model' => 'northwind-large',
                'choices' => [['message' => ['content' => 'Answered without a release.'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 5],
            ]),
        ]);
    }

    // -- the journey ---------------------------------------------------------

    public function test_an_unknown_provider_goes_from_nothing_to_answering_using_only_the_panel(): void
    {
        // 1. Add it. No preset is chosen: the presets are a convenience for
        //    providers Aziv AI happens to know, and a provider it does not
        //    know must be no harder to add than typing three fields.
        Livewire::actingAs($this->admin)
            ->test(CreateAiProvider::class)
            ->fillForm([
                'name' => self::NAME,
                'slug' => self::SLUG,
                'adapter_type' => OpenAiCompatibleAdapter::KEY,
                'api_base_url' => 'https://'.self::HOST.'/v1',
                'auth_method' => 'bearer',
                'status' => AiProvider::STATUS_DISABLED,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $provider = AiProvider::where('slug', self::SLUG)->firstOrFail();

        // 2. Paste the key, on the screen where keys are pasted.
        Livewire::actingAs($this->admin)
            ->test(CredentialsRelationManager::class, [
                'ownerRecord' => $provider,
                'pageClass' => EditAiProvider::class,
            ])
            ->callAction(TestAction::make('create')->table(), data: [
                'label' => 'Main account',
                'credential' => 'nw-live-SECRETSECRET9876',
                'status' => AiProviderCredential::STATUS_ACTIVE,
                'priority' => 100,
            ])
            ->assertHasNoActionErrors();

        $credential = $provider->fresh()->credentials()->firstOrFail();

        // Stored the Phase 3 way, with nothing new written for this provider.
        $this->assertSame('9876', $credential->hint);
        $this->assertNotSame('nw-live-SECRETSECRET9876', $credential->getRawOriginal('credential'));

        // 3. Check the connection from the provider list.
        Livewire::actingAs($this->admin)
            ->test(ListAiProviders::class)
            ->callAction(TestAction::make('test')->table($provider));

        $tested = ActivityLog::where('action', 'provider.tested')
            ->latest('id')->firstOrFail();

        $this->assertTrue($tested->after['success']);
        // The audit records the RESULT, never the key that produced it.
        $this->assertStringNotContainsString('SECRETSECRET', json_encode($tested->after));

        // 4. Refresh the catalog. The models come from the provider, so no
        //    model identifier had to be known in advance (Rule 5).
        Livewire::actingAs($this->admin)
            ->test(ListAiProviders::class)
            ->callAction(TestAction::make('sync')->table($provider));

        $this->assertSame(2, $provider->models()->count());

        $model = $provider->models()->where('model_identifier', 'northwind-large')->firstOrFail();

        // They arrive switched off, as every synced model does.
        $this->assertFalse($model->is_enabled);

        // 5. Switch one on, and switch the provider on.
        Livewire::actingAs($this->admin)
            ->test(ListAiModels::class)
            ->call('updateTableColumnState', 'is_enabled', (string) $model->getKey(), true);

        Livewire::actingAs($this->admin)
            ->test(EditAiProvider::class, ['record' => $provider->getRouteKey()])
            ->fillForm(['status' => AiProvider::STATUS_ACTIVE])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($model->fresh()->is_enabled);
        $this->assertSame(AiProvider::STATUS_ACTIVE, $provider->fresh()->status);

        // 6. A customer chats, and is served by a company that did not exist
        //    when this code was written.
        $customer = User::factory()->create();
        $customer->assignRole(PermissionRegistry::CUSTOMER);
        $customer = $customer->fresh();

        $conversation = app(ConversationService::class)->start($customer);
        $turn = app(ChatService::class)->beginTurn($conversation, 'Does this work?');
        $content = app(ChatService::class)->complete($turn['assistant']);

        $settled = $turn['assistant']->fresh();

        $this->assertSame('Answered without a release.', $content);
        $this->assertSame(Message::STATUS_COMPLETE, $settled->status);
        $this->assertSame($model->getKey(), $settled->model_id);
        $this->assertSame($provider->getKey(), $settled->provider_id);

        // And it is costed and logged like any other provider, because the
        // layers above never learned its name.
        $this->assertDatabaseHas('api_usage_logs', [
            'provider_id' => $provider->getKey(),
            'model_id' => $model->getKey(),
        ]);
        $this->assertDatabaseHas('routing_logs', ['selected_model_id' => $model->getKey()]);
    }

    /**
     * The proof that step 1 to 6 needed no development.
     *
     * If any of it had, the provider's name, slug or address would have had to
     * be written down somewhere in `app/` — a branch, a constant, a special
     * case. Nothing else can make this assertion fail.
     */
    public function test_the_provider_is_named_nowhere_in_the_application_code(): void
    {
        $offenders = [];

        foreach ($this->phpFilesIn(app_path()) as $file) {
            if (str_contains(strtolower((string) file_get_contents($file)), self::TOKEN)) {
                $offenders[] = str_replace(base_path().'/', '', $file);
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            'A provider that is supposed to be pure configuration is named in application code:',
            ...$offenders,
            'Adding a provider must never require a code change.',
        ]));
    }

    /**
     * The other half of the same proof: putting it into service registered no
     * new adapter. It is served by the one class that already speaks the shape
     * most of the market uses.
     */
    public function test_serving_it_added_no_adapter(): void
    {
        $registry = app(ProviderRegistry::class);

        $this->assertSame(
            ['openai', 'anthropic', 'gemini', 'openai_compatible', 'custom_http'],
            $registry->keys(),
            'An adapter appeared. If a provider needed one, "no code change" is no longer true.',
        );

        $provider = AiProvider::create([
            'name' => self::NAME,
            'adapter_type' => OpenAiCompatibleAdapter::KEY,
            'api_base_url' => 'https://'.self::HOST.'/v1',
            'status' => AiProvider::STATUS_ACTIVE,
        ]);

        $this->assertInstanceOf(OpenAiCompatibleAdapter::class, $registry->for($provider));
    }

    /**
     * A preset is a shortcut, never a requirement.
     *
     * Every provider Aziv AI ships a preset for could be added by typing the
     * same fields by hand — which is why a provider with no preset is not a
     * second-class citizen.
     */
    public function test_a_preset_only_fills_in_what_the_owner_would_have_typed(): void
    {
        $registry = app(ProviderRegistry::class);

        foreach ($registry->presets() as $key => $preset) {
            $this->assertTrue(
                $registry->has($preset['adapter_type']),
                $key.' points at an adapter that does not exist.',
            );

            // Facts about the provider, all of them editable afterwards.
            $this->assertArrayHasKey('name', $preset);
            $this->assertArrayHasKey('key_source', $preset);
            $this->assertStringStartsWith('https://', $preset['api_base_url']);
        }

        // Most of the list needs no adapter of its own. That ratio is the
        // point of §12, so it is asserted rather than admired.
        $compatible = collect($registry->presets())
            ->where('adapter_type', OpenAiCompatibleAdapter::KEY)
            ->count();

        $this->assertGreaterThanOrEqual(5, $compatible);
    }

    /** @return array<int, string> */
    private function phpFilesIn(string $directory): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
