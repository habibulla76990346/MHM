<?php

namespace Tests\Feature\Admin;

use App\Domains\AI\Adapters\OpenAiAdapter;
use App\Domains\AI\Models\AiModel;
use App\Domains\AI\Models\AiProvider;
use App\Domains\AI\Models\AiProviderCredential;
use App\Domains\AI\Models\ApiUsageLog;
use App\Domains\AI\Models\ExchangeRate;
use App\Domains\AI\Models\RoutingLog;
use App\Domains\AI\Models\UsageDailySummary;
use App\Domains\AI\Routing\CircuitBreaker;
use App\Domains\AI\Routing\RejectionReason;
use App\Domains\Security\Services\PermissionRegistry;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The two Phase 5 admin screens: money, and why a request went where it went.
 */
class RoutingAndCostPagesTest extends TestCase
{
    use RefreshDatabase;

    private AiProvider $provider;

    private AiModel $model;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->provider = AiProvider::create([
            'name' => 'Costed Provider',
            'adapter_type' => OpenAiAdapter::KEY,
            'api_base_url' => 'https://costed.test/v1',
            'status' => AiProvider::STATUS_ACTIVE,
        ]);

        AiProviderCredential::create([
            'provider_id' => $this->provider->getKey(),
            'label' => 'Key',
            'credential' => 'sk-secretvalue-KEYKEYKEY1234',
        ]);

        $this->model = AiModel::create([
            'provider_id' => $this->provider->getKey(),
            'model_identifier' => 'costed-1',
            'display_name' => 'Costed One',
            'is_enabled' => true,
        ]);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user->fresh();
    }

    private function summary(float $cost, float $credits): void
    {
        UsageDailySummary::create([
            'summary_date' => now()->subDay()->toDateString(),
            'provider_id' => $this->provider->getKey(),
            'model_id' => $this->model->getKey(),
            'requests' => 10,
            'failures' => 1,
            'input_tokens' => 5000,
            'output_tokens' => 2000,
            'provider_cost' => $cost,
            'provider_currency' => 'USD',
            'provider_cost_base' => $cost * 80,
            'credit_cost' => $credits,
            'p50_latency_ms' => 900,
        ]);
    }

    // -- usage and costs ------------------------------------------------------

    public function test_an_owner_sees_spend_revenue_and_what_they_kept(): void
    {
        $this->summary(cost: 1.0, credits: 120.0);

        $this->actingAs($this->userWithRole(PermissionRegistry::SUPER_ADMIN))
            ->get('/admin/usage-and-costs')
            ->assertOk()
            ->assertSee('What you spent')
            ->assertSee('Costed Provider')
            // 1 USD at 80, against 120 charged.
            ->assertSee('INR 80.00')
            ->assertSee('INR 40.00');
    }

    public function test_a_currency_with_no_rate_on_file_is_called_out_rather_than_shown_as_zero(): void
    {
        ApiUsageLog::create([
            'provider_id' => $this->provider->getKey(),
            'model_id' => $this->model->getKey(),
            'provider_cost' => 5,
            'provider_currency' => 'USD',
            'credit_cost' => 1,
            'occurred_at' => now()->subHours(2),
        ]);

        $this->actingAs($this->userWithRole(PermissionRegistry::SUPER_ADMIN))
            ->get('/admin/usage-and-costs')
            ->assertOk()
            ->assertSee('No exchange rate on file');
    }

    public function test_a_known_rate_removes_the_warning(): void
    {
        ExchangeRate::create([
            'base_currency' => 'USD', 'quote_currency' => 'INR',
            'rate' => 80, 'effective_on' => now()->subDay()->toDateString(),
        ]);

        ApiUsageLog::create([
            'provider_id' => $this->provider->getKey(),
            'model_id' => $this->model->getKey(),
            'provider_cost' => 5,
            'provider_currency' => 'USD',
            'credit_cost' => 1,
            'occurred_at' => now()->subHours(2),
        ]);

        $this->actingAs($this->userWithRole(PermissionRegistry::SUPER_ADMIN))
            ->get('/admin/usage-and-costs')
            ->assertOk()
            ->assertDontSee('No exchange rate on file');
    }

    public function test_a_content_manager_cannot_see_the_money(): void
    {
        $response = $this->actingAs($this->userWithRole(PermissionRegistry::CONTENT_MANAGER))
            ->get('/admin/usage-and-costs');

        $this->assertContains($response->getStatusCode(), [403, 404]);
    }

    // -- routing and health ---------------------------------------------------

    public function test_a_decision_explains_itself_including_what_was_rejected(): void
    {
        RoutingLog::create([
            'request_type' => 'chat',
            'routing_mode' => 'auto',
            'capability_required' => ['chat'],
            'candidates' => [
                [
                    'model_id' => $this->model->getKey(),
                    'model' => 'costed-1',
                    'provider' => 'Costed Provider',
                    'eligible' => true,
                    'score' => 0.81,
                ],
                [
                    'model' => 'other-1',
                    'provider' => 'Other',
                    'eligible' => false,
                    'rejection_code' => RejectionReason::CIRCUIT_OPEN,
                    'rejected_because' => RejectionReason::explain(RejectionReason::CIRCUIT_OPEN),
                ],
            ],
            'selected_provider_id' => $this->provider->getKey(),
            'selected_model_id' => $this->model->getKey(),
            'fallback_depth' => 1,
            'decision_reason' => 'Fallback #1: Costed One via Costed Provider',
            'decided_at' => now(),
        ]);

        $this->actingAs($this->userWithRole(PermissionRegistry::SUPER_ADMIN))
            ->get('/admin/routing-and-health')
            ->assertOk()
            ->assertSee('Costed One')
            ->assertSee('Substituted after 1 failure(s)')
            ->assertSee('Why not the others?');
    }

    public function test_a_provider_that_has_never_been_used_is_not_shown_as_failing(): void
    {
        // No evidence is not bad evidence: a newly added provider must not
        // look broken, or an owner will go looking for a fault that is not
        // there.
        $this->actingAs($this->userWithRole(PermissionRegistry::SUPER_ADMIN))
            ->get('/admin/routing-and-health')
            ->assertOk()
            ->assertSee('Not used yet');
    }

    public function test_an_open_circuit_is_visible_to_an_owner(): void
    {
        app(CircuitBreaker::class)->forceOpen($this->provider);

        $this->actingAs($this->userWithRole(PermissionRegistry::SUPER_ADMIN))
            ->get('/admin/routing-and-health')
            ->assertOk()
            ->assertSee('Forced open by an administrator');
    }

    // -- the rule that applies to every screen --------------------------------

    public function test_neither_screen_can_leak_a_credential(): void
    {
        $this->summary(cost: 1.0, credits: 5.0);

        RoutingLog::create([
            'routing_mode' => 'auto',
            'candidates' => [['model' => 'costed-1', 'provider' => 'Costed Provider', 'eligible' => true]],
            'selected_model_id' => $this->model->getKey(),
            'decided_at' => now(),
        ]);

        $admin = $this->userWithRole(PermissionRegistry::SUPER_ADMIN);

        foreach (['/admin/usage-and-costs', '/admin/routing-and-health'] as $url) {
            $body = $this->actingAs($admin)->get($url)->assertOk()->getContent();

            $this->assertStringNotContainsString('sk-secretvalue', $body);
            $this->assertStringNotContainsString('KEYKEYKEY', $body);
        }
    }
}
