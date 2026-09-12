<?php

namespace Tests\Feature\Chat;

use App\Domains\AI\Adapters\OpenAiAdapter;
use App\Domains\AI\Exceptions\ProviderFailed;
use App\Domains\AI\Models\AiModel;
use App\Domains\AI\Models\AiModelCapability;
use App\Domains\AI\Models\AiModelPrice;
use App\Domains\AI\Models\AiProvider;
use App\Domains\AI\Models\AiProviderCredential;
use App\Domains\AI\Models\ApiUsageLog;
use App\Domains\AI\Support\Capability;
use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Models\PlanFeature;
use App\Domains\Billing\Models\PlanModelAccess;
use App\Domains\Billing\Services\SubscriptionService;
use App\Domains\Chat\Models\Message;
use App\Domains\Chat\Services\ChatService;
use App\Domains\Chat\Services\ConversationService;
use App\Domains\Chat\Support\ChatRefused;
use App\Domains\Credits\Models\CreditHold;
use App\Domains\Credits\Models\CreditLedgerEntry;
use App\Domains\Credits\Services\CreditService;
use App\Domains\Security\Services\PermissionRegistry;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Where billing meets the product: **a failed AI call releases its hold and
 * charges nothing**, and a plan's limits are actually enforced.
 *
 * The customer pays for answers, never for attempts. A system that charged for
 * a provider outage would be taking money for the owner's problem.
 */
class CreditEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private AiModel $model;

    private Plan $plan;

    private bool $providerFails = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::factory()->create();
        $this->user->assignRole(PermissionRegistry::CUSTOMER);
        $this->user = $this->user->fresh();

        $provider = AiProvider::create([
            'name' => 'Metered',
            'adapter_type' => OpenAiAdapter::KEY,
            'api_base_url' => 'https://metered.test/v1',
            'status' => AiProvider::STATUS_ACTIVE,
        ]);

        AiProviderCredential::create([
            'provider_id' => $provider->getKey(),
            'label' => 'Key',
            'credential' => 'sk-metered-KEYKEYKEY1234',
        ]);

        $this->model = AiModel::create([
            'provider_id' => $provider->getKey(),
            'model_identifier' => 'metered-1',
            'display_name' => 'Metered One',
            'is_enabled' => true,
            'context_window' => 32000,
            'max_output_tokens' => 1000,
        ]);

        AiModelCapability::syncForModel($this->model, [Capability::CHAT, Capability::STREAMING]);

        // 1 credit per 1,000 output tokens, 0.5 per 1,000 input.
        foreach ([['per_1k_input', 0.5], ['per_1k_output', 1.0]] as [$unit, $credits]) {
            AiModelPrice::create([
                'model_id' => $this->model->getKey(),
                'unit' => $unit,
                'provider_cost' => $credits / 100,
                'credit_cost' => $credits,
                'currency' => 'USD',
                'effective_from' => now()->subYear(),
            ]);
        }

        $this->plan = Plan::create([
            'name' => 'Metered Plan',
            'billing_cycle' => 'monthly',
            'credits_per_period' => 100,
            'is_default' => true,
            'is_public' => true,
            'status' => Plan::STATUS_ACTIVE,
        ]);

        Http::fake(['*' => function () {
            if ($this->providerFails) {
                return Http::response(['error' => ['message' => 'upstream exploded']], 500);
            }

            return Http::response([
                'model' => 'metered-1',
                'choices' => [['message' => ['content' => 'A metered answer'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 2000, 'completion_tokens' => 1000],
            ]);
        }]);

        settings()->set('routing.max_retries', 0);
        settings()->set('routing.max_fallback_depth', 0);
    }

    private function chat(): ChatService
    {
        return app(ChatService::class);
    }

    private function credits(): CreditService
    {
        return app(CreditService::class);
    }

    private function conversation()
    {
        return app(ConversationService::class)->start($this->user);
    }

    // -- the gate item --------------------------------------------------------

    public function test_a_failed_call_releases_its_hold_and_charges_nothing(): void
    {
        $this->providerFails = true;

        $turn = $this->chat()->beginTurn($this->conversation(), 'Will this cost me?');

        // Held before the provider was called.
        $this->assertSame(1, CreditHold::where('status', CreditHold::HELD)->count());

        try {
            $this->chat()->complete($turn['assistant']);
            $this->fail('Expected the provider failure to surface.');
        } catch (ProviderFailed) {
            // Expected — the provider is down.
        }

        $balance = $this->credits()->balance($this->user)->fresh();

        // Untouched. A provider outage is the owner's problem, not a charge.
        $this->assertEqualsWithDelta(100.0, (float) $balance->confirmed_balance, 0.000001);
        $this->assertEqualsWithDelta(0.0, (float) $balance->held_balance, 0.000001);
        $this->assertSame(CreditHold::RELEASED, CreditHold::first()->status);
        $this->assertTrue($this->credits()->reconciles($this->user));
    }

    public function test_a_successful_call_charges_what_it_actually_used(): void
    {
        $turn = $this->chat()->beginTurn($this->conversation(), 'What does this cost?');
        $this->chat()->complete($turn['assistant']);

        // 2 input × 0.5 + 1 output × 1.0 = 2 credits.
        $balance = $this->credits()->balance($this->user)->fresh();

        $this->assertEqualsWithDelta(98.0, (float) $balance->confirmed_balance, 0.000001);
        $this->assertEqualsWithDelta(0.0, (float) $balance->held_balance, 0.000001);
        $this->assertSame(CreditHold::SETTLED, CreditHold::first()->status);
        $this->assertTrue($this->credits()->reconciles($this->user));
    }

    public function test_the_charge_matches_the_usage_log_exactly(): void
    {
        $turn = $this->chat()->beginTurn($this->conversation(), 'Reconcile me.');
        $this->chat()->complete($turn['assistant']);

        $usage = ApiUsageLog::latest('id')->first();
        $deduction = CreditLedgerEntry::where('entry_type', 'deduction')->first();

        // One number, not two. What the customer is billed and what the
        // owner's margin report shows come from the same figure.
        $this->assertEqualsWithDelta((float) $usage->credit_cost, abs((float) $deduction->amount), 0.000001);
    }

    public function test_a_customer_out_of_credit_is_refused_before_the_provider_is_called(): void
    {
        // Spend the allowance down to nothing.
        $this->credits()->balance($this->user);
        app(SubscriptionService::class)->ensureSubscription($this->user);
        $this->credits()->expire($this->user, 100, 'Test: spend it all');

        try {
            $this->chat()->beginTurn($this->conversation(), 'Anything left?');
            $this->fail('Expected a refusal.');
        } catch (ChatRefused $e) {
            $this->assertStringContainsString('credits', $e->getMessage());
        }

        // Nothing was sent, and nothing was saved — the question is not stored
        // for an answer that will never come.
        Http::assertNothingSent();
        $this->assertSame(0, Message::count());
    }

    public function test_nothing_is_metered_until_the_owner_publishes_a_plan(): void
    {
        // A priced model and no published plan: the platform still works.
        $this->plan->forceFill(['status' => Plan::STATUS_DRAFT])->save();

        $turn = $this->chat()->beginTurn($this->conversation(), 'Unmetered?');
        $content = $this->chat()->complete($turn['assistant']);

        $this->assertSame('A metered answer', $content);
        $this->assertSame(0, CreditHold::count());
    }

    // -- plan limits ----------------------------------------------------------

    public function test_a_hard_message_limit_stops_a_customer(): void
    {
        PlanFeature::create([
            'plan_id' => $this->plan->getKey(),
            'key' => 'messages_per_day',
            'value' => '1',
            'limit_type' => PlanFeature::HARD,
        ]);

        $turn = $this->chat()->beginTurn($this->conversation(), 'First');
        $this->chat()->complete($turn['assistant']);

        $this->expectException(ChatRefused::class);
        $this->expectExceptionMessage('all 1 messages');

        $this->chat()->beginTurn($this->conversation(), 'Second');
    }

    public function test_a_soft_message_limit_lets_the_customer_through(): void
    {
        PlanFeature::create([
            'plan_id' => $this->plan->getKey(),
            'key' => 'messages_per_day',
            'value' => '1',
            'limit_type' => PlanFeature::SOFT,
        ]);

        $first = $this->chat()->beginTurn($this->conversation(), 'First');
        $this->chat()->complete($first['assistant']);

        // "Soft" means the owner wants to know, not to stop serving.
        $second = $this->chat()->beginTurn($this->conversation(), 'Second');

        $this->assertSame(Message::STATUS_PENDING, $second['assistant']->status);
    }

    public function test_an_unlimited_plan_has_no_ceiling(): void
    {
        PlanFeature::create([
            'plan_id' => $this->plan->getKey(),
            'key' => 'messages_per_day',
            'value' => '1',
            'limit_type' => PlanFeature::UNLIMITED,
        ]);

        for ($i = 0; $i < 3; $i++) {
            $turn = $this->chat()->beginTurn($this->conversation(), "Message {$i}");
            $this->chat()->complete($turn['assistant']);
        }

        $this->assertSame(3, Message::where('role', Message::ROLE_USER)->count());
    }

    public function test_a_plan_that_forbids_a_model_never_routes_to_it(): void
    {
        PlanModelAccess::create([
            'plan_id' => $this->plan->getKey(),
            'ai_model_id' => $this->model->getKey(),
            'is_allowed' => false,
        ]);

        // The only model is denied, so there is nothing to answer with — and
        // the customer is told that, not shown a model they cannot use.
        $this->expectException(ChatRefused::class);

        $this->chat()->beginTurn($this->conversation(), 'Denied?');
    }

    public function test_a_plan_with_no_access_rows_allows_everything(): void
    {
        // An owner who has never opened the access screen has a working plan,
        // and a newly synced model does not silently become unavailable.
        $turn = $this->chat()->beginTurn($this->conversation(), 'Allowed?');

        $this->assertSame($this->model->getKey(), $turn['assistant']->model_id);
    }

    public function test_a_plans_attachment_limit_is_the_tighter_of_the_two(): void
    {
        settings()->set('chat.max_attachments', 5);

        PlanFeature::create([
            'plan_id' => $this->plan->getKey(),
            'key' => 'max_attachments',
            'value' => '1',
            'limit_type' => PlanFeature::HARD,
        ]);

        $this->expectException(ChatRefused::class);
        $this->expectExceptionMessage('at most 1');

        $this->chat()->beginTurn($this->conversation(), 'Two files', [1, 2]);
    }

    public function test_a_new_account_lands_on_the_default_plan_when_it_first_chats(): void
    {
        $turn = $this->chat()->beginTurn($this->conversation(), 'First ever message');

        $subscription = app(SubscriptionService::class)->ensureSubscription($this->user);

        $this->assertSame($this->plan->getKey(), $subscription->plan_id);
        // And the plan's allowance is there to pay for the turn.
        $this->assertGreaterThan(0, (float) $this->credits()->balance($this->user)->confirmed_balance);
        $this->assertNotNull($turn['assistant']);
    }
}
