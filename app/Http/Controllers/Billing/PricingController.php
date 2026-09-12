<?php

namespace App\Http\Controllers\Billing;

use App\Domains\Billing\Models\Country;
use App\Domains\Billing\Models\Currency;
use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Services\EntitlementService;
use App\Domains\Tax\Models\CustomerTaxProfile;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The public pricing page (§20).
 *
 * Shows only PUBLISHED plans priced in a currency the owner actually sells in.
 * A plan with no price in the visitor's currency is not converted at a live
 * rate — a price that moves daily and ends in odd decimals reads as a mistake,
 * and Addendum F is explicit that per-market pricing is deliberate.
 */
class PricingController extends Controller
{
    public function __invoke(Request $request, EntitlementService $entitlements): View
    {
        $currency = $this->currencyFor($request);

        $plans = Plan::purchasable()
            ->with(['prices', 'features'])
            ->orderBy('sort_order')
            ->get()
            // Free plans need no price; paid ones are hidden in a market they
            // have not been priced for, rather than shown at a converted
            // number nobody decided on.
            ->filter(fn (Plan $plan) => $plan->is_free || $plan->priceIn($currency->code) !== null)
            ->values();

        return view('billing.pricing', [
            'plans' => $plans,
            'currency' => $currency,
            'currentPlanId' => auth()->check() ? $entitlements->plan(auth()->user())?->getKey() : null,
        ]);
    }

    /**
     * Which currency to quote in.
     *
     * The customer's own billing country decides where it can, then the
     * owner's reporting currency. Never a guess from an IP address, which is
     * wrong often enough to be embarrassing on a pricing page.
     */
    private function currencyFor(Request $request): Currency
    {
        $base = strtoupper((string) settings('billing.base_currency'));

        $preferred = auth()->check()
            ? CustomerTaxProfile::where('user_id', auth()->id())->value('country')
            : null;

        if ($preferred) {
            $countryCurrency = Country::where('code', $preferred)->value('default_currency');

            $currency = Currency::where('code', $countryCurrency)->where('is_active', true)->first();

            if ($currency) {
                return $currency;
            }
        }

        return Currency::where('code', $base)->first()
            ?? Currency::where('is_base', true)->first()
            ?? new Currency(['code' => $base ?: 'INR', 'symbol' => '', 'decimal_places' => 2, 'display_format' => '{amount} {code}']);
    }
}
