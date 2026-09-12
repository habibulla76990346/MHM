<?php

namespace Tests\Feature\AI;

use App\Domains\AI\Adapters\AnthropicAdapter;
use App\Domains\AI\Adapters\GeminiAdapter;
use App\Domains\AI\Adapters\OpenAiAdapter;
use App\Domains\AI\Adapters\OpenAiCompatibleAdapter;
use App\Domains\AI\Models\AiModel;
use App\Domains\AI\Models\AiModelCapability;
use App\Domains\AI\Models\AiProvider;
use App\Domains\AI\Models\AiProviderCredential;
use App\Domains\AI\Routing\AiRouter;
use App\Domains\AI\Routing\RoutingMode;
use App\Domains\AI\Support\Capability;
use App\Domains\Chat\Models\Conversation;
use App\Domains\Chat\Models\Message;
use App\Domains\Chat\Services\ChatService;
use App\Domains\Chat\Services\ConversationService;
use App\Domains\Security\Services\PermissionRegistry;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Phase 7 gate: **routing spans every provider family, and fallback
 * crosses between them.**
 *
 * Four families with genuinely different wire shapes are configured here.
 * Nothing above the adapter layer knows which is which — the router picks by
 * capability and health, and when one dies the substitute may be a completely
 * different company with a completely different API. If that were not true,
 * "multi-provider" would mean "one provider with spares of the same kind".
 */
class MultiProviderRoutingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    /** @var array<string, string> family => 'ok' | an HTTP status to fail with */
    private array $behaviour = [];

    /** @var array<string, int> how many calls each family received */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::factory()->create();
        $this->user->assignRole(PermissionRegistry::CUSTOMER);
        $this->user = $this->user->fresh();

        settings()->set('routing.max_retries', 0);

        $this->installStub();
    }

    /**
     * One provider per family, each on its own hostname.
     *
     * @return array<string, AiModel>
     */
    private function families(array $only = []): array
    {
        $families = [
            'openai' => [OpenAiAdapter::KEY, 'bearer', 1],
            'anthropic' => [AnthropicAdapter::KEY, 'header', 2],
            'gemini' => [GeminiAdapter::KEY, 'query', 3],
            'compatible' => [OpenAiCompatibleAdapter::KEY, 'bearer', 4],
        ];

        $models = [];

        foreach ($families as $family => [$adapter, $auth, $priority]) {
            if ($only !== [] && ! in_array($family, $only, true)) {
                continue;
            }

            $provider = AiProvider::create([
                'name' => ucfirst($family),
                'adapter_type' => $adapter,
                'api_base_url' => "https://{$family}.families.test/v1",
                'auth_method' => $auth,
                'status' => AiProvider::STATUS_ACTIVE,
                'priority' => $priority,
            ]);

            AiProviderCredential::create([
                'provider_id' => $provider->getKey(),
                'label' => 'Key',
                'credential' => 'sk-'.$family.'-KEYKEYKEY1234',
            ]);

            $model = AiModel::create([
                'provider_id' => $provider->getKey(),
                'model_identifier' => $family.'-model',
                'display_name' => ucfirst($family).' Model',
                'is_enabled' => true,
                'context_window' => 32000,
                'max_output_tokens' => 2048,
            ]);

            AiModelCapability::syncForModel($model, [Capability::CHAT, Capability::STREAMING]);

            $this->behaviour[$family] = 'ok';
            $this->calls[$family] = 0;
            $models[$family] = $model->fresh('provider');
        }

        return $models;
    }

    /**
     * ONE stub reading mutable state.
     *
     * A second Http::fake() for the same pattern does not replace the first,
     * so re-faking to kill a provider silently keeps serving the healthy
     * response — a trap that has bitten this project five times.
     */
    private function installStub(): void
    {
        Http::fake(['*' => function ($request) {
            $family = collect(['openai', 'anthropic', 'gemini', 'compatible'])
                ->first(fn (string $f) => str_contains($request->url(), $f.'.families.test')) ?? 'openai';

            $this->calls[$family] = ($this->calls[$family] ?? 0) + 1;

            if (($this->behaviour[$family] ?? 'ok') !== 'ok') {
                return Http::response(['error' => ['message' => 'down']], (int) $this->behaviour[$family]);
            }

            // Each family answers in ITS OWN shape. The point of the test is
            // that nothing above the adapter has to know which.
            return Http::response(match ($family) {
                'anthropic' => [
                    'model' => 'anthropic-model',
                    'content' => [['type' => 'text', 'text' => 'Answer from anthropic']],
                    'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 4],
                ],
                'gemini' => [
                    'candidates' => [[
                        'content' => ['parts' => [['text' => 'Answer from gemini']]],
                        'finishReason' => 'STOP',
                    ]],
                    'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 4],
                ],
                default => [
                    'model' => $family.'-model',
                    'choices' => [['message' => ['content' => 'Answer from '.$family], 'finish_reason' => 'stop']],
                    'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 4],
                ],
            });
        }]);
    }

    private function chat(): ChatService
    {
        return app(ChatService::class);
    }

    private function conversation()
    {
        return app(ConversationService::class)->start($this->user);
    }

    // -- routing spans every family ------------------------------------------

    public function test_the_router_considers_every_family_as_a_candidate(): void
    {
        $models = $this->families();

        $decision = app(AiRouter::class)->route([Capability::CHAT], RoutingMode::AUTO);

        $considered = collect($decision->candidates)->pluck('model.id')->all();

        foreach ($models as $family => $model) {
            $this->assertContains($model->getKey(), $considered, $family.' was not even considered.');
        }

        $this->assertTrue($decision->chosen());
    }

    public function test_the_owners_priority_order_picks_between_families(): void
    {
        $models = $this->families();

        // "Your preferred order" ignores cost and speed entirely, so the
        // answer is purely the owner's ranking — across companies.
        $decision = app(AiRouter::class)->route([Capability::CHAT], RoutingMode::ADMIN_PREFERRED);

        $this->assertSame($models['openai']->getKey(), $decision->model?->getKey());
    }

    public function test_each_family_answers_through_the_same_chat_path(): void
    {
        foreach (['openai', 'anthropic', 'gemini', 'compatible'] as $family) {
            $this->refreshDatabaseForFamily();

            $models = $this->families([$family]);

            $turn = $this->chat()->beginTurn($this->conversation(), 'Hello '.$family);
            $content = $this->chat()->complete($turn['assistant']);

            // One code path, four wire formats, one normalised answer.
            $this->assertSame('Answer from '.$family, $content);
            $this->assertSame($models[$family]->getKey(), $turn['assistant']->fresh()->model_id);
            $this->assertSame(Message::STATUS_COMPLETE, $turn['assistant']->fresh()->status);
        }
    }

    // -- fallback crosses families -------------------------------------------

    public function test_a_dead_provider_falls_back_to_a_different_family(): void
    {
        $models = $this->families();

        // The first choice is dead; the substitute speaks a completely
        // different API.
        $this->behaviour['openai'] = '500';

        $turn = $this->chat()->beginTurn($this->conversation(), 'Are you there?');
        $this->assertSame($models['openai']->getKey(), $turn['assistant']->model_id);

        $content = $this->chat()->complete($turn['assistant']);

        $settled = $turn['assistant']->fresh();

        $this->assertNotSame($models['openai']->getKey(), $settled->model_id);
        $this->assertStringStartsWith('Answer from ', $content);
        $this->assertSame(Message::STATUS_COMPLETE, $settled->status);
        // No user-visible error, and the customer never learns a company
        // changed underneath them.
        $this->assertNull($settled->error_class);
    }

    public function test_it_keeps_crossing_families_until_one_answers(): void
    {
        settings()->set('routing.max_fallback_depth', 3);

        $this->families();

        // Three of the four are down. The answer comes from whichever family
        // is left, and the customer sees one reply.
        $this->behaviour['openai'] = '500';
        $this->behaviour['anthropic'] = '503';
        $this->behaviour['gemini'] = '500';

        $turn = $this->chat()->beginTurn($this->conversation(), 'Anyone?');
        $content = $this->chat()->complete($turn['assistant']);

        $this->assertSame('Answer from compatible', $content);
        $this->assertGreaterThan(0, $turn['assistant']->fresh()->fallback_depth);
    }

    public function test_a_vision_request_still_never_crosses_to_a_model_that_cannot_see(): void
    {
        $models = $this->families();

        // Only one family can see, and it is the one that is down. The
        // capability guard must hold ACROSS families too — otherwise
        // "multi-provider" would be the thing that broke it.
        AiModelCapability::syncForModel($models['anthropic'], [
            Capability::CHAT, Capability::STREAMING, Capability::VISION,
        ]);

        $this->behaviour['anthropic'] = '500';

        $decision = app(AiRouter::class)->route(
            [Capability::CHAT, Capability::VISION],
            RoutingMode::AUTO,
            null,
            null,
            null,
            0,
            [$models['anthropic']->getKey()],
        );

        $this->assertFalse($decision->chosen(), 'A text-only family was offered a vision request.');
    }

    public function test_health_is_tracked_per_family(): void
    {
        $models = $this->families();

        $this->behaviour['openai'] = '500';

        $turn = $this->chat()->beginTurn($this->conversation(), 'Hello');
        $this->chat()->complete($turn['assistant']);

        // The failure is recorded against the provider that failed, not
        // against whoever answered in the end.
        $this->assertDatabaseHas('provider_health_logs', [
            'provider_id' => $models['openai']->provider_id,
            'success' => false,
        ]);

        $this->assertDatabaseHas('provider_health_logs', [
            'provider_id' => $turn['assistant']->fresh()->provider_id,
            'success' => true,
        ]);
    }

    /** Each pass of the per-family loop needs a clean catalog. */
    private function refreshDatabaseForFamily(): void
    {
        Message::query()->delete();
        Conversation::query()->forceDelete();
        AiModel::query()->delete();
        AiProvider::query()->forceDelete();

        $this->behaviour = [];
        $this->calls = [];
    }
}
