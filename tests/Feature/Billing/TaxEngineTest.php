<?php

namespace Tests\Feature\Billing;

use App\Domains\Tax\Models\CustomerTaxProfile;
use App\Domains\Tax\Models\TaxJurisdiction;
use App\Domains\Tax\Models\TaxRate;
use App\Domains\Tax\Models\TaxRule;
use App\Domains\Tax\Models\TaxSettings;
use App\Domains\Tax\Services\TaxEngine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The configurable tax engine (Addendum F).
 *
 * EVERY RATE AND EVERY NAME IN THIS FILE IS TEST DATA, exactly as it would be
 * administrator data in production. The engine under test does not know what
 * any of them mean — which is the requirement being proved.
 */
class TaxEngineTest extends TestCase
{
    use RefreshDatabase;

    private TaxEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->engine = app(TaxEngine::class);
    }

    private function configure(string $pricingMode = TaxSettings::EXCLUSIVE, bool $enabled = true): TaxSettings
    {
        return tap(TaxSettings::current(), fn (TaxSettings $s) => $s->forceFill([
            'legal_name' => 'Test Business Pvt Ltd',
            'address_lines' => '1 Example Road',
            'country' => 'IN',
            'state' => 'Karnataka',
            'tax_registration_number' => 'TESTREG0001',
            'default_place_of_supply' => 'Karnataka',
            'tax_enabled' => $enabled,
            'pricing_mode' => $pricingMode,
            'rounding_mode' => 'half_up',
        ])->save());
    }

    private function jurisdiction(string $name = 'Domestic', ?string $state = null, string $country = 'IN'): TaxJurisdiction
    {
        return TaxJurisdiction::create([
            'name' => $name,
            'country' => $country,
            'state' => $state,
            'is_domestic' => $country === 'IN',
            'priority' => $state ? 10 : 50,
            'is_active' => true,
        ]);
    }

    private function rate(TaxJurisdiction $j, string $name, float $percent, ?string $from = null): TaxRate
    {
        return TaxRate::create([
            'jurisdiction_id' => $j->getKey(),
            'name' => $name,
            'code' => strtoupper(substr($name, 0, 8)),
            'rate_percent' => $percent,
            'applies_to' => 'all',
            'effective_from' => $from ?? now()->subYear()->toDateString(),
            'is_active' => true,
        ]);
    }

    private function rule(TaxJurisdiction $j, string $condition, array $rateIds, int $priority = 10): TaxRule
    {
        return TaxRule::create([
            'jurisdiction_id' => $j->getKey(),
            'condition_type' => $condition,
            'rate_ids' => $rateIds,
            'priority' => $priority,
            'is_active' => true,
        ]);
    }

    private function profile(array $attributes = []): CustomerTaxProfile
    {
        return CustomerTaxProfile::create(array_merge([
            'user_id' => User::factory()->create()->getKey(),
            'country' => 'IN',
            'state' => 'Karnataka',
        ], $attributes));
    }

    // -- off by default -------------------------------------------------------

    public function test_tax_is_off_until_an_administrator_turns_it_on(): void
    {
        // Nothing configured at all: the shipped state.
        $result = $this->engine->compute(1000);

        $this->assertEqualsWithDelta(0.0, $result->taxTotal, 0.000001);
        $this->assertEqualsWithDelta(1000.0, $result->gross, 0.000001);
        // And it SAYS why. A silent zero is what an auditor asks about.
        $this->assertNotNull($result->note);
    }

    public function test_enabling_tax_without_configuring_it_charges_nothing_and_says_so(): void
    {
        TaxSettings::current()->forceFill(['tax_enabled' => true])->save();

        $result = $this->engine->compute(1000);

        $this->assertEqualsWithDelta(0.0, $result->taxTotal, 0.000001);
        $this->assertStringContainsString('incomplete', (string) $result->note);
    }

    // -- the structural conditions -------------------------------------------

    public function test_a_customer_in_the_same_state_gets_the_components_that_rule_names(): void
    {
        $this->configure();
        $j = $this->jurisdiction();

        // Two components, as an administrator might configure for a domestic
        // sale. The engine has no idea what they are called.
        $a = $this->rate($j, 'Component A', 9);
        $b = $this->rate($j, 'Component B', 9);
        $this->rule($j, TaxRule::SAME_STATE, [$a->getKey(), $b->getKey()]);

        $result = $this->engine->compute(1000, $this->profile(['state' => 'Karnataka']));

        $this->assertCount(2, $result->components);
        $this->assertEqualsWithDelta(90.0, $result->components[0]['tax_amount'], 0.01);
        $this->assertEqualsWithDelta(180.0, $result->taxTotal, 0.01);
        $this->assertEqualsWithDelta(1180.0, $result->gross, 0.01);
    }

    public function test_a_customer_in_another_state_gets_the_other_rule(): void
    {
        $this->configure();
        $j = $this->jurisdiction();

        $split = $this->rate($j, 'Component A', 9);
        $single = $this->rate($j, 'Component C', 18);

        $this->rule($j, TaxRule::SAME_STATE, [$split->getKey()]);
        $this->rule($j, TaxRule::DIFFERENT_STATE, [$single->getKey()]);

        $result = $this->engine->compute(1000, $this->profile(['state' => 'Maharashtra']));

        $this->assertCount(1, $result->components);
        $this->assertSame('Component C', $result->components[0]['name']);
        $this->assertEqualsWithDelta(180.0, $result->taxTotal, 0.01);
    }

    public function test_a_customer_in_another_country_is_treated_as_an_export(): void
    {
        $this->configure();
        $j = $this->jurisdiction();

        $domestic = $this->rate($j, 'Component A', 18);
        $zero = $this->rate($j, 'Zero rated export', 0);

        $this->rule($j, TaxRule::SAME_STATE, [$domestic->getKey()]);
        $this->rule($j, TaxRule::EXPORT, [$zero->getKey()]);

        $result = $this->engine->compute(1000, $this->profile(['country' => 'GB', 'state' => null]));

        $this->assertTrue($result->isExport);
        $this->assertEqualsWithDelta(0.0, $result->taxTotal, 0.000001);
        // Still recorded as a component, so the invoice shows WHY it was zero.
        $this->assertCount(1, $result->components);
    }

    public function test_a_specific_condition_beats_a_catch_all_whatever_its_priority(): void
    {
        $this->configure();
        $j = $this->jurisdiction();

        $always = $this->rate($j, 'Fallback', 18);
        $exportRate = $this->rate($j, 'Export', 0);

        // The catch-all is given the LOWER number, which would normally win.
        $this->rule($j, TaxRule::ALWAYS, [$always->getKey()], priority: 1);
        $this->rule($j, TaxRule::EXPORT, [$exportRate->getKey()], priority: 90);

        $result = $this->engine->compute(500, $this->profile(['country' => 'US', 'state' => null]));

        $this->assertSame('Export', $result->components[0]['name']);
    }

    public function test_a_valid_exemption_removes_tax_and_records_the_reference(): void
    {
        $this->configure();
        $j = $this->jurisdiction();
        $rate = $this->rate($j, 'Component A', 18);
        $this->rule($j, TaxRule::SAME_STATE, [$rate->getKey()]);

        $result = $this->engine->compute(1000, $this->profile([
            'exemption_reference' => 'EX-2026-01',
            'exemption_expires_at' => now()->addYear()->toDateString(),
        ]));

        $this->assertEqualsWithDelta(0.0, $result->taxTotal, 0.000001);
        $this->assertStringContainsString('EX-2026-01', (string) $result->note);
    }

    public function test_an_expired_exemption_does_not_exempt(): void
    {
        $this->configure();
        $j = $this->jurisdiction();
        $rate = $this->rate($j, 'Component A', 18);
        $this->rule($j, TaxRule::SAME_STATE, [$rate->getKey()]);

        // A lapsed certificate that still exempted a customer would understate
        // tax on every invoice after it expired — and the business owes it.
        $result = $this->engine->compute(1000, $this->profile([
            'exemption_reference' => 'EX-OLD',
            'exemption_expires_at' => now()->subDay()->toDateString(),
        ]));

        $this->assertEqualsWithDelta(180.0, $result->taxTotal, 0.01);
    }

    // -- pricing mode ---------------------------------------------------------

    public function test_inclusive_pricing_extracts_the_tax_rather_than_adding_it(): void
    {
        $this->configure(TaxSettings::INCLUSIVE);
        $j = $this->jurisdiction();
        $rate = $this->rate($j, 'Component A', 18);
        $this->rule($j, TaxRule::SAME_STATE, [$rate->getKey()]);

        $result = $this->engine->compute(1180, $this->profile());

        // The customer pays 1180 either way. Getting this backwards would
        // charge them 1392.40.
        $this->assertEqualsWithDelta(1000.0, $result->net, 0.05);
        $this->assertEqualsWithDelta(180.0, $result->taxTotal, 0.05);
        $this->assertEqualsWithDelta(1180.0, $result->gross, 0.05);
    }

    // -- dated rates ----------------------------------------------------------

    public function test_a_rate_is_chosen_by_the_invoice_date_not_todays(): void
    {
        $this->configure();
        $j = $this->jurisdiction();

        $old = $this->rate($j, 'Component A', 12, now()->subYears(2)->toDateString());
        $old->forceFill(['effective_until' => now()->subMonths(6)->toDateString()])->save();

        $new = $this->rate($j, 'Component A', 18, now()->subMonths(6)->addDay()->toDateString());

        $this->rule($j, TaxRule::SAME_STATE, [$old->getKey(), $new->getKey()]);

        $lastYear = $this->engine->compute(1000, $this->profile(), now()->subYear());
        $today = $this->engine->compute(1000, $this->profile());

        // Reissuing last year's invoice must produce last year's figures.
        $this->assertEqualsWithDelta(120.0, $lastYear->taxTotal, 0.01);
        $this->assertEqualsWithDelta(180.0, $today->taxTotal, 0.01);
    }

    public function test_a_period_with_no_rate_in_force_charges_nothing_and_says_so(): void
    {
        $this->configure();
        $j = $this->jurisdiction();
        $rate = $this->rate($j, 'Component A', 18, now()->subMonth()->toDateString());
        $this->rule($j, TaxRule::SAME_STATE, [$rate->getKey()]);

        $result = $this->engine->compute(1000, $this->profile(), now()->subYear());

        $this->assertEqualsWithDelta(0.0, $result->taxTotal, 0.000001);
        $this->assertStringContainsString('in force', (string) $result->note);
    }

    public function test_a_state_jurisdiction_wins_over_a_national_one(): void
    {
        $this->configure();

        $national = $this->jurisdiction('India');
        $state = $this->jurisdiction('Karnataka', 'Karnataka');

        $nationalRate = $this->rate($national, 'National', 18);
        $stateRate = $this->rate($state, 'State special', 5);

        $this->rule($national, TaxRule::SAME_STATE, [$nationalRate->getKey()]);
        $this->rule($state, TaxRule::SAME_STATE, [$stateRate->getKey()]);

        $result = $this->engine->compute(1000, $this->profile(['state' => 'Karnataka']));

        $this->assertSame('State special', $result->components[0]['name']);
    }
}
