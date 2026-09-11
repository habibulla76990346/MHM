<?php

namespace Tests\Feature\Chat;

use App\Domains\AI\Adapters\OpenAiAdapter;
use App\Domains\AI\Exceptions\ProviderFailed;
use App\Domains\AI\Models\AiModel;
use App\Domains\AI\Models\AiModelCapability;
use App\Domains\AI\Models\AiProvider;
use App\Domains\AI\Models\AiProviderCredential;
use App\Domains\AI\Models\ProviderCircuitState;
use App\Domains\AI\Routing\CircuitBreaker;
use App\Domains\AI\Routing\RetryPolicy;
use App\Domains\AI\Support\Capability;
use App\Domains\AI\Support\ErrorClass;
use App\Domains\Chat\Models\Conversation;
use App\Domains\Chat\Models\Message;
use App\Domains\Chat\Services\ChatService;
use App\Domains\Chat\Services\ConversationService;
use App\Domains\Chat\Support\ChatRefused;
use App\Domains\Files\Models\File;
use App\Domains\Security\Services\PermissionRegistry;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Phase 5 gate, second half: what happens when a provider stops working.
 *
 * Two providers, each on its own hostname, so a test can kill ONE of them and
 * the fallback has somewhere real to go. The whole point of these assertions
 * is that a customer sees an answer, not an apology.
 */
class FallbackTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private AiModel $primary;

    private AiModel $secondary;

    /**
     * What each provider will do next.
     *
     * A string applies to every call; a LIST is consumed one entry per call,
     * which is how "fails once, then works" is expressed without a second
     * Http::fake — see installStub().
     *
     * @var array<string, string|array<int, string>>
     */
    private array $behaviour = [];

    /** @var array<string, int> how many calls each provider actually received */
    private array $calls = ['primary' => 0, 'secondary' => 0];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::factory()->create();
        $this->user->assignRole(PermissionRegistry::CUSTOMER);
        $this->user = $this->user->fresh();

        // Priority decides which is tried first, so "the primary failed" is a
        // statement about a known provider rather than about whichever the
        // scorer happened to like.
        $this->primary = $this->model('primary', priority: 1);
        $this->secondary = $this->model('secondary', priority: 2);

        $this->behaviour = ['primary' => 'ok', 'secondary' => 'ok'];

        // Retries are what this suite is about, so they must not make it slow.
        settings()->set('routing.retry_base_ms', 50);

        $this->installStub();
    }

    private function model(string $name, int $priority, array $capabilities = [Capability::CHAT, Capability::STREAMING]): AiModel
    {
        $provider = AiProvider::create([
            'name' => ucfirst($name),
            'adapter_type' => OpenAiAdapter::KEY,
            'api_base_url' => "https://{$name}.test/v1",
            'status' => AiProvider::STATUS_ACTIVE,
            'priority' => $priority,
        ]);

        AiProviderCredential::create([
            'provider_id' => $provider->getKey(),
            'label' => 'Key',
            'credential' => 'sk-'.$name.'-KEYKEYKEY1234',
        ]);

        $model = AiModel::create([
            'provider_id' => $provider->getKey(),
            'model_identifier' => $name.'-model',
            'display_name' => ucfirst($name).' Model',
            'is_enabled' => true,
            'context_window' => 32000,
            'max_output_tokens' => 2048,
        ]);

        AiModelCapability::syncForModel($model, $capabilities);

        return $model->fresh('provider');
    }

    /**
     * ONE stub, reading mutable state.
     *
     * A second Http::fake() for the same pattern does NOT replace the first —
     * Laravel keeps the first match — so re-faking to simulate a provider
     * going down silently keeps serving the healthy response, and every
     * "fallback happened" assertion passes vacuously. That trap has bitten
     * this project three times.
     */
    private function installStub(): void
    {
        Http::fake(['*' => function ($request) {
            $host = str_contains($request->url(), 'primary.test') ? 'primary' : 'secondary';
            $this->calls[$host]++;

            $behaviour = $this->behaviour[$host] ?? 'ok';

            if (is_array($behaviour)) {
                // One entry per call; the last one repeats once the list runs
                // out, so a test only has to describe the change it cares about.
                $behaviour = array_shift($this->behaviour[$host]) ?? 'ok';

                if ($this->behaviour[$host] === []) {
                    $this->behaviour[$host] = 'ok';
                }
            }

            if ($behaviour !== 'ok') {
                return Http::response(
                    // Deliberately echoing the Authorization header back, which
                    // is exactly what several real APIs do — and the reason a
                    // provider's own words never reach a customer.
                    ['error' => ['message' => 'Bearer sk-'.$host.'-KEYKEYKEY1234 was rejected']],
                    (int) $behaviour,
                    $behaviour === '429' ? ['Retry-After' => '0'] : [],
                );
            }

            if ($request->data()['stream'] ?? false) {
                $body = '';

                foreach (['Answer ', 'from ', $host] as $fragment) {
                    $body .= 'data: '.json_encode(['choices' => [['delta' => ['content' => $fragment]]]])."\n";
                }

                return Http::response($body."data: [DONE]\n");
            }

            return Http::response([
                'model' => $host.'-model',
                'choices' => [['message' => ['content' => "Answer from {$host}"], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 8],
            ]);
        }]);
    }

    private function chat(): ChatService
    {
        return app(ChatService::class);
    }

    private function conversation(): Conversation
    {
        return app(ConversationService::class)->start($this->user);
    }

    // -- fallback -------------------------------------------------------------

    public function test_killing_a_provider_produces_an_answer_from_another_one(): void
    {
        $this->behaviour['primary'] = '500';

        $conversation = $this->conversation();
        $turn = $this->chat()->beginTurn($conversation, 'Are you there?');

        $this->assertSame($this->primary->getKey(), $turn['assistant']->model_id);

        $content = $this->chat()->complete($turn['assistant']);

        // No user-visible error. The customer got an answer.
        $this->assertSame('Answer from secondary', $content);

        $assistant = $turn['assistant']->fresh();
        $this->assertSame(Message::STATUS_COMPLETE, $assistant->status);
        $this->assertSame($this->secondary->getKey(), $assistant->model_id);
        $this->assertNull($assistant->error_class);
        // Recorded, so an owner seeing many of these knows to look.
        $this->assertSame(1, $assistant->fallback_depth);
    }

    public function test_a_vision_request_never_falls_back_to_a_model_that_cannot_see(): void
    {
        // The primary can see; the secondary cannot. If the capability guard
        // were not re-applied on fallback, the picture question would be
        // answered by a model that never saw the picture — and would look like
        // a perfectly good answer.
        AiModelCapability::syncForModel($this->primary, [Capability::CHAT, Capability::STREAMING, Capability::VISION]);

        $this->behaviour['primary'] = '500';

        $conversation = $this->conversation();
        $turn = $this->chat()->beginTurn($conversation, 'What is in this picture?', [$this->imageFile()->getKey()]);

        $this->assertSame($this->primary->getKey(), $turn['assistant']->model_id);

        try {
            $this->chat()->complete($turn['assistant']);
            $this->fail('A vision request fell back to a text-only model.');
        } catch (ProviderFailed $e) {
            $this->assertSame(ErrorClass::PROVIDER_ERROR, $e->errorClass);
        }

        $assistant = $turn['assistant']->fresh();
        $this->assertSame(Message::STATUS_FAILED, $assistant->status);
        // Still on the model that CAN see. It never moved.
        $this->assertSame($this->primary->getKey(), $assistant->model_id);
    }

    public function test_a_pinned_conversation_is_never_silently_answered_by_another_model(): void
    {
        $conversation = $this->conversation();
        $conversation->forceFill([
            'routing_mode' => Conversation::ROUTING_SPECIFIC_MODEL,
            'pinned_model_id' => $this->primary->getKey(),
        ])->save();

        $this->behaviour['primary'] = '500';

        $turn = $this->chat()->beginTurn($conversation->fresh(), 'Only you.');

        $this->expectException(ProviderFailed::class);

        try {
            $this->chat()->complete($turn['assistant']);
        } finally {
            // "One model" means one model — the setting would be a lie
            // otherwise. The other provider was never even asked.
            $this->assertSame($this->primary->getKey(), $turn['assistant']->fresh()->model_id);
            $this->assertSame(0, $this->calls['secondary']);
        }
    }

    public function test_fallback_stops_at_the_configured_depth(): void
    {
        settings()->set('routing.max_fallback_depth', 0);
        $this->behaviour['primary'] = '500';

        $conversation = $this->conversation();
        $turn = $this->chat()->beginTurn($conversation, 'Hello?');

        $this->expectException(ProviderFailed::class);

        $this->chat()->complete($turn['assistant']);
    }

    public function test_a_dead_provider_is_invisible_to_a_streamed_answer(): void
    {
        $this->behaviour['primary'] = '500';

        $conversation = $this->conversation();
        $turn = $this->chat()->beginTurn($conversation, 'Stream me something.');

        $fragments = iterator_to_array($this->chat()->stream($turn['assistant']));

        $this->assertSame('Answer from secondary', implode('', $fragments));
        $this->assertSame(Message::STATUS_COMPLETE, $turn['assistant']->fresh()->status);
    }

    // -- retries --------------------------------------------------------------

    public function test_a_transient_failure_is_retried_on_the_same_provider(): void
    {
        settings()->set('routing.max_retries', 2);

        // Rate-limited once, then fine.
        $this->behaviour['primary'] = ['429', 'ok'];

        $turn = $this->chat()->beginTurn($this->conversation(), 'Try twice.');
        $content = $this->chat()->complete($turn['assistant']);

        $this->assertSame('Answer from primary', $content);
        $this->assertSame(2, $this->calls['primary']);
        // The SAME provider, not a different one: a 429 is a blip, and
        // spreading one impatient request across every provider an owner has
        // is not a remedy.
        $this->assertSame(0, $this->calls['secondary']);
        $this->assertSame($this->primary->getKey(), $turn['assistant']->fresh()->model_id);
    }

    public function test_a_rejected_key_is_never_retried(): void
    {
        settings()->set('routing.max_retries', 3);
        settings()->set('routing.max_fallback_depth', 0);

        $this->behaviour['primary'] = '401';

        $turn = $this->chat()->beginTurn($this->conversation(), 'Hello.');

        try {
            $this->chat()->complete($turn['assistant']);
        } catch (ProviderFailed $e) {
            $this->assertSame(ErrorClass::AUTHENTICATION, $e->errorClass);
        }

        // Retrying a rejected key does not make it valid; it spends the
        // customer's patience and fails identically.
        $this->assertSame(1, $this->calls['primary']);
    }

    public function test_a_provider_asking_for_a_wait_is_obeyed(): void
    {
        $policy = app(RetryPolicy::class);

        $this->assertSame(2000, $policy->delayMs(1, 2));
        // However long it asks for, a customer is not held indefinitely.
        $this->assertSame(20000, $policy->delayMs(1, 600));
        $this->assertSame(30, $policy->retryAfterFrom('30'));
        $this->assertNotNull($policy->retryAfterFrom(gmdate('D, d M Y H:i:s \G\M\T', time() + 45)));
    }

    // -- the circuit breaker --------------------------------------------------

    public function test_repeated_failure_opens_the_circuit_and_a_probe_closes_it(): void
    {
        settings()->set('routing.circuit_failure_threshold', 2);
        settings()->set('routing.circuit_cooldown_seconds', 5);
        settings()->set('routing.max_retries', 0);
        settings()->set('routing.max_fallback_depth', 0);

        $breaker = app(CircuitBreaker::class);
        $provider = $this->primary->provider;

        $this->behaviour['primary'] = '500';

        for ($i = 0; $i < 2; $i++) {
            $conversation = $this->conversation();
            $turn = $this->chat()->beginTurn($conversation, "Attempt {$i}");

            try {
                $this->chat()->complete($turn['assistant']);
            } catch (ProviderFailed) {
                // Expected — the provider is down.
            }
        }

        $this->assertTrue($breaker->isOpen($provider));
        // Mirrored for the Admin Panel, which is where an owner sees it.
        $this->assertSame(
            ProviderCircuitState::OPEN,
            ProviderCircuitState::where('provider_id', $provider->getKey())->value('state'),
        );

        // Past the cooldown it is not open any more — it is ready for ONE
        // probe. A breaker that never probed would mean a recovered provider
        // never came back on its own.
        $this->travel(6)->seconds();
        $this->assertFalse($breaker->isOpen($provider));
        $this->assertTrue($breaker->isProbing($provider));

        $this->behaviour['primary'] = 'ok';

        $turn = $this->chat()->beginTurn($this->conversation(), 'Are you back?');
        $this->assertSame('Answer from primary', $this->chat()->complete($turn['assistant']));

        $this->assertFalse($breaker->isOpen($provider));
        $this->assertFalse($breaker->isProbing($provider));
    }

    public function test_a_rejected_key_does_not_open_the_circuit(): void
    {
        settings()->set('routing.circuit_failure_threshold', 1);
        settings()->set('routing.max_retries', 0);
        settings()->set('routing.max_fallback_depth', 0);

        $this->behaviour['primary'] = '401';

        $turn = $this->chat()->beginTurn($this->conversation(), 'Hello.');

        try {
            $this->chat()->complete($turn['assistant']);
        } catch (ProviderFailed) {
            // Expected.
        }

        // A configuration mistake is not the provider being down, and taking a
        // healthy provider out of rotation over one would hide the real fix.
        $this->assertFalse(app(CircuitBreaker::class)->isOpen($this->primary->provider));
    }

    public function test_an_administrator_can_put_a_provider_back_in_rotation(): void
    {
        $breaker = app(CircuitBreaker::class);
        $provider = $this->primary->provider;

        $breaker->forceOpen($provider);
        $this->assertTrue($breaker->isOpen($provider));

        $breaker->reset($provider);

        // The LIVE state, not just the mirror row: resetting only the database
        // copy would report success and change nothing.
        $this->assertFalse($breaker->isOpen($provider));
        $this->assertSame(
            ProviderCircuitState::CLOSED,
            ProviderCircuitState::where('provider_id', $provider->getKey())->value('state'),
        );
    }

    public function test_a_customer_is_never_shown_a_providers_own_words(): void
    {
        settings()->set('routing.max_fallback_depth', 0);
        settings()->set('routing.max_retries', 0);

        $this->behaviour['primary'] = '500';

        $turn = $this->chat()->beginTurn($this->conversation(), 'Hello.');

        try {
            $this->chat()->complete($turn['assistant']);
            $this->fail('Expected the provider failure to surface.');
        } catch (ProviderFailed $e) {
            $this->assertStringNotContainsString('sk-', $e->getMessage());
            $this->assertStringNotContainsString('KEYKEYKEY', $e->getMessage());
        }

        $assistant = $turn['assistant']->fresh();
        $this->assertSame(ErrorClass::PROVIDER_ERROR, $assistant->error_class);
        $this->assertStringNotContainsString('sk-', (string) $assistant->content);
    }

    // -- refusals -------------------------------------------------------------

    public function test_every_provider_being_out_of_rotation_says_so_without_blaming_the_owner(): void
    {
        $breaker = app(CircuitBreaker::class);
        $breaker->forceOpen($this->primary->provider);
        $breaker->forceOpen($this->secondary->provider);

        $this->expectException(ChatRefused::class);
        $this->expectExceptionMessage('temporarily unavailable');

        $this->chat()->beginTurn($this->conversation(), 'Anyone home?');
    }

    private function imageFile(): File
    {
        return File::create([
            'user_id' => $this->user->getKey(),
            'disk' => 'local',
            'path' => 'chat/picture.png',
            'stored_name' => 'picture-'.str_repeat('b', 8).'.png',
            'original_name' => 'picture.png',
            'declared_mime' => 'image/png',
            'detected_mime' => 'image/png',
            'extension' => 'png',
            'size_bytes' => 128,
            'checksum' => str_repeat('a', 64),
            'purpose' => 'chat',
        ]);
    }
}
