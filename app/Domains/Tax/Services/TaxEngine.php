<?php

namespace App\Domains\Tax\Services;

use App\Domains\Tax\Models\CustomerTaxProfile;
use App\Domains\Tax\Models\TaxJurisdiction;
use App\Domains\Tax\Models\TaxRate;
use App\Domains\Tax\Models\TaxRule;
use App\Domains\Tax\Models\TaxSettings;
use App\Domains\Tax\Support\TaxComputation;
use App\Models\User;

/**
 * The configurable tax engine (Addendum F).
 *
 * NOT ONE TAX NAME, RATE OR CODE APPEARS IN THIS FILE. It knows how to compare
 * a customer's country and state to the business's, how to read dated rates,
 * and how to add or extract a percentage. What those percentages are called
 * and what they are worth is entirely data an administrator entered — which is
 * the owner's requirement, and the only structure under which changing a rate
 * cannot rewrite history.
 *
 * TWO DETAILS CARRY RULE 2:
 *   - rates are selected by the INVOICE DATE, never today's;
 *   - the result is COPIED onto the invoice, never referenced.
 *
 * Together they mean reissuing a March invoice produces the March document,
 * for ever.
 */
class TaxEngine
{
    /**
     * @param  float  $amount  the price as configured — net if pricing is exclusive, gross if inclusive
     * @param  string  $subject  'subscription' | 'credits' — matches `tax_rates.applies_to`
     */
    public function compute(
        float $amount,
        ?CustomerTaxProfile $profile = null,
        ?\DateTimeInterface $on = null,
        string $subject = 'subscription',
    ): TaxComputation {
        $on ??= now();
        $settings = TaxSettings::current();
        $mode = $settings->pricing_mode ?: TaxSettings::EXCLUSIVE;

        // Tax off, or switched on but never configured. Either way nothing is
        // charged and the invoice SAYS SO — a zero with no explanation is what
        // an auditor asks about.
        if (! $settings->tax_enabled) {
            return TaxComputation::none($amount, __('Tax not applicable'), $mode);
        }

        if (! $settings->isUsable()) {
            return TaxComputation::none($amount, __('Tax is enabled but the business tax details are incomplete'), $mode);
        }

        if ($profile?->isExempt()) {
            return TaxComputation::none($amount, __('Exempt — reference :ref', ['ref' => $profile->exemption_reference]), $mode);
        }

        $country = strtoupper((string) ($profile?->country ?: $settings->country));
        $state = $profile?->state ?: ($profile ? null : $settings->default_place_of_supply);
        $isExport = $country !== strtoupper((string) $settings->country);

        $jurisdiction = $this->jurisdictionFor($country, $state, $isExport);

        if (! $jurisdiction) {
            return TaxComputation::none($amount, __('No tax jurisdiction is configured for this customer'), $mode);
        }

        $rule = $this->ruleFor($jurisdiction, $settings, $profile, $isExport);

        if (! $rule) {
            return TaxComputation::none($amount, __('No tax rule matched this customer'), $mode);
        }

        $rates = $this->ratesFor($rule, $on, $subject);

        if ($rates === []) {
            return TaxComputation::none($amount, __('No tax rate was in force on this date'), $mode);
        }

        return $this->apply($amount, $rates, $settings, $jurisdiction, $state, $isExport, $mode);
    }

    /** The same calculation for a hypothetical customer, for the preview tool. */
    public function previewFor(User $user, float $amount, ?\DateTimeInterface $on = null): TaxComputation
    {
        return $this->compute($amount, CustomerTaxProfile::where('user_id', $user->getKey())->first(), $on);
    }

    // -- the pipeline --------------------------------------------------------

    /**
     * The most specific active jurisdiction covering this customer.
     *
     * Priority decides, so a state-level jurisdiction can override a national
     * one without the code needing to know which states are special.
     */
    private function jurisdictionFor(string $country, ?string $state, bool $isExport): ?TaxJurisdiction
    {
        $candidates = TaxJurisdiction::where('is_active', true)
            ->orderBy('priority')
            ->get();

        foreach ($candidates as $jurisdiction) {
            if ($jurisdiction->covers($country, $state)) {
                return $jurisdiction;
            }
        }

        // An export still needs a jurisdiction to hang the rule on — the
        // business's own, which is where an export rule (often zero-rated)
        // lives.
        if ($isExport) {
            return $candidates->first(fn (TaxJurisdiction $j) => $j->is_domestic);
        }

        return null;
    }

    private function ruleFor(
        TaxJurisdiction $jurisdiction,
        TaxSettings $settings,
        ?CustomerTaxProfile $profile,
        bool $isExport,
    ): ?TaxRule {
        $conditions = $this->conditionsMatching($settings, $profile, $isExport);

        return TaxRule::where('jurisdiction_id', $jurisdiction->getKey())
            ->where('is_active', true)
            ->whereIn('condition_type', $conditions)
            ->orderBy('priority')
            ->get()
            // Ordered by how specific the condition is, then by the admin's
            // own priority: "export" must beat "always" even if someone gave
            // the catch-all a lower number.
            ->sortBy(fn (TaxRule $rule) => [
                array_search($rule->condition_type, $conditions, true),
                $rule->priority,
            ])
            ->first();
    }

    /**
     * Which structural conditions describe this customer, most specific first.
     *
     * @return array<int, string>
     */
    private function conditionsMatching(TaxSettings $settings, ?CustomerTaxProfile $profile, bool $isExport): array
    {
        $conditions = [];

        if ($isExport) {
            $conditions[] = TaxRule::EXPORT;
        } else {
            $sameState = $profile?->state === null
                || strcasecmp(trim((string) $profile->state), trim((string) $settings->state)) === 0;

            $conditions[] = $sameState ? TaxRule::SAME_STATE : TaxRule::DIFFERENT_STATE;
        }

        $conditions[] = $profile?->isRegistered()
            ? TaxRule::CUSTOMER_REGISTERED
            : TaxRule::CUSTOMER_UNREGISTERED;

        $conditions[] = TaxRule::ALWAYS;

        return $conditions;
    }

    /**
     * The rates the rule names, as they stood ON THE INVOICE DATE.
     *
     * @return array<int, TaxRate>
     */
    private function ratesFor(TaxRule $rule, \DateTimeInterface $on, string $subject): array
    {
        $ids = array_map('intval', (array) $rule->rate_ids);

        if ($ids === []) {
            return [];
        }

        return TaxRate::whereIn('id', $ids)
            ->effectiveOn($on)
            ->orderBy('id')
            ->get()
            ->filter(fn (TaxRate $rate) => $rate->appliesTo($subject))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, TaxRate>  $rates
     */
    private function apply(
        float $amount,
        array $rates,
        TaxSettings $settings,
        TaxJurisdiction $jurisdiction,
        ?string $state,
        bool $isExport,
        string $mode,
    ): TaxComputation {
        $totalRate = array_sum(array_map(fn (TaxRate $r) => (float) $r->rate_percent, $rates));

        // Inclusive pricing means the configured price ALREADY CONTAINS the
        // tax, so the net is extracted rather than the tax added. Getting this
        // backwards overcharges every customer by the tax rate.
        $net = $mode === TaxSettings::INCLUSIVE && $totalRate > 0
            ? $amount / (1 + $totalRate / 100)
            : $amount;

        $components = [];
        $taxTotal = 0.0;

        foreach ($rates as $rate) {
            // Rounded PER COMPONENT, because that is what appears on the
            // invoice and what a return is filed from. Rounding only the total
            // makes the components not add up to it.
            $taxAmount = $this->round($net * (float) $rate->rate_percent / 100, $settings->rounding_mode);

            $components[] = [
                'name' => $rate->name,
                'code' => $rate->code,
                'rate_percent' => (float) $rate->rate_percent,
                'taxable_amount' => $this->round($net, $settings->rounding_mode),
                'tax_amount' => $taxAmount,
                'jurisdiction' => $jurisdiction->name,
            ];

            $taxTotal += $taxAmount;
        }

        $net = $this->round($net, $settings->rounding_mode);
        $taxTotal = $this->round($taxTotal, $settings->rounding_mode);

        return new TaxComputation(
            net: $net,
            taxTotal: $taxTotal,
            gross: $this->round($net + $taxTotal, $settings->rounding_mode),
            components: $components,
            pricingMode: $mode,
            isExport: $isExport,
            placeOfSupply: $state ?: $settings->default_place_of_supply,
            jurisdiction: $jurisdiction->name,
        );
    }

    /** Rounding is the administrator's choice; jurisdictions differ on it. */
    private function round(float $value, ?string $mode): float
    {
        return match ($mode) {
            'half_even' => round($value, 2, PHP_ROUND_HALF_EVEN),
            'up' => ceil($value * 100) / 100,
            'down' => floor($value * 100) / 100,
            default => round($value, 2, PHP_ROUND_HALF_UP),
        };
    }
}
