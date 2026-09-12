<?php

namespace Database\Seeders;

use App\Domains\Billing\Models\Country;
use App\Domains\Billing\Models\Currency;
use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Models\PlanFeature;
use App\Domains\Billing\Services\InvoiceNumberAllocator;
use App\Domains\Payments\Models\PaymentGatewayRecord;
use App\Domains\Payments\Services\PaymentGatewayRegistry;
use App\Domains\Tax\Models\TaxJurisdiction;
use App\Domains\Tax\Models\TaxSettings;
use Illuminate\Database\Seeder;

/**
 * The reference data billing needs, and NOTHING an owner has not decided.
 *
 * The line this seeder walks is the whole of Addendum F: it ships the SHAPES —
 * currencies, countries, empty jurisdictions, plan skeletons — and not one
 * rate, price or treatment. A seeded rate would be a tax decision made by a
 * developer on the owner's behalf, and the first invoice would carry it.
 *
 * Everything here is inactive or unpriced until an administrator says
 * otherwise. Re-running is safe: every row is matched on its natural key.
 */
class BillingSeeder extends Seeder
{
    public function run(): void
    {
        $this->currencies();
        $this->countries();
        $this->taxTemplates();
        $this->invoiceNumbering();
        $this->planSkeletons();
        $this->paymentGateways();
    }

    /**
     * The gateways this build knows how to talk to — DISABLED, in sandbox, and
     * with no credentials.
     *
     * Seeding the row saves an owner constructing it by hand and gives the
     * webhook URL something to belong to. It cannot take a payment until they
     * enter credentials and switch it on, which is a deliberate, audited act.
     */
    private function paymentGateways(): void
    {
        foreach (app(PaymentGatewayRegistry::class)->all() as $key => $adapterClass) {
            $adapter = app($adapterClass);

            PaymentGatewayRecord::firstOrCreate(
                ['key' => $key],
                [
                    'name' => ucfirst($key),
                    'adapter_class' => $adapterClass,
                    'status' => PaymentGatewayRecord::STATUS_DISABLED,
                    'mode' => PaymentGatewayRecord::MODE_SANDBOX,
                    'priority' => 10,
                    // Recorded from the ADAPTER, which is the only thing that
                    // knows what it has actually implemented.
                    'capabilities' => $adapter->capabilities(),
                    'checkout_mode' => $adapter->checkoutMode(),
                    // Empty means "whatever the merchant account allows" — a
                    // fact about the owner's agreement, not something software
                    // can determine.
                    'supported_currencies' => [],
                    'supported_countries' => [],
                    'api_base_url' => defined($adapterClass.'::DEFAULT_BASE_URL')
                        ? constant($adapterClass.'::DEFAULT_BASE_URL')
                        : null,
                ],
            );
        }
    }

    /**
     * Currencies, with the decimal places each actually has.
     *
     * The zero- and three-decimal entries are here from the start on purpose:
     * they are what catch a formatter that assumed two.
     */
    private function currencies(): void
    {
        $currencies = [
            ['INR', 'Indian Rupee', '₹', 2, true],
            ['USD', 'US Dollar', '$', 2, false],
            ['EUR', 'Euro', '€', 2, false],
            ['GBP', 'Pound Sterling', '£', 2, false],
            ['AED', 'UAE Dirham', 'د.إ', 2, false],
            ['SGD', 'Singapore Dollar', 'S$', 2, false],
            ['AUD', 'Australian Dollar', 'A$', 2, false],
            ['CAD', 'Canadian Dollar', 'C$', 2, false],
            // Zero decimals. Formatting this at two invents money that cannot
            // be paid.
            ['JPY', 'Japanese Yen', '¥', 0, false],
            // Three. Rounding this at two loses it.
            ['KWD', 'Kuwaiti Dinar', 'د.ك', 3, false],
            ['BHD', 'Bahraini Dinar', '.د.ب', 3, false],
        ];

        foreach ($currencies as $index => [$code, $name, $symbol, $decimals, $isBase]) {
            Currency::firstOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'symbol' => $symbol,
                    'decimal_places' => $decimals,
                    'display_format' => '{symbol}{amount}',
                    // Only the base currency is on by default. An owner
                    // enables the markets they actually sell in.
                    'is_active' => $isBase,
                    'is_base' => $isBase,
                    'sort_order' => $index,
                ],
            );
        }
    }

    /**
     * Countries that can be billed, with whether a state is needed.
     *
     * `requires_state` is the only opinion here, and it is a fact about the
     * country's own tax system rather than a decision about the business.
     */
    private function countries(): void
    {
        $countries = [
            ['IN', 'India', 'INR', true],
            ['US', 'United States', 'USD', true],
            ['CA', 'Canada', 'CAD', true],
            ['AU', 'Australia', 'AUD', true],
            ['GB', 'United Kingdom', 'GBP', false],
            ['DE', 'Germany', 'EUR', false],
            ['FR', 'France', 'EUR', false],
            ['NL', 'Netherlands', 'EUR', false],
            ['IE', 'Ireland', 'EUR', false],
            ['AE', 'United Arab Emirates', 'AED', false],
            ['SG', 'Singapore', 'SGD', false],
            ['JP', 'Japan', 'JPY', false],
        ];

        foreach ($countries as [$code, $name, $currency, $requiresState]) {
            Country::firstOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'default_currency' => $currency,
                    'requires_state' => $requiresState,
                    // Enabled by the owner, market by market.
                    'is_billing_enabled' => false,
                ],
            );
        }
    }

    /**
     * Empty jurisdictions to hang rules on — INACTIVE, and carrying no rates.
     *
     * A jurisdiction is a place, which is a fact. A rate is a decision, and
     * this seeder makes none: there is nothing to accidentally charge, and an
     * administrator's first act is to add the rates their accountant confirms.
     */
    private function taxTemplates(): void
    {
        $templates = [
            ['Domestic — same state', 'IN', null, true, 20],
            ['Domestic — other states', 'IN', null, true, 30],
            ['Export — outside the country', 'IN', null, false, 90],
        ];

        foreach ($templates as [$name, $country, $state, $domestic, $priority]) {
            TaxJurisdiction::firstOrCreate(
                ['name' => $name, 'country' => $country],
                [
                    'state' => $state,
                    'is_domestic' => $domestic,
                    'priority' => $priority,
                    // Off. Nothing is charged until the owner configures and
                    // enables it.
                    'is_active' => false,
                ],
            );
        }

        // The settings row exists so the admin screen has something to edit;
        // tax_enabled defaults to false.
        TaxSettings::current();
    }

    private function invoiceNumbering(): void
    {
        app(InvoiceNumberAllocator::class)->sequence(InvoiceNumberAllocator::DEFAULT_KEY);
        app(InvoiceNumberAllocator::class)->sequence('credit_note');
    }

    /**
     * Three plan SKELETONS, unpriced and unpublished.
     *
     * §19 names FREE, PRO and PREMIUM, so the shapes are here to save an owner
     * typing — but every one is a draft with no price, and the platform stays
     * unmetered until one is published. Naming them is a starting point; what
     * they cost and what they include is the owner's to decide.
     */
    private function planSkeletons(): void
    {
        $plans = [
            ['Free', 'free', 'For trying Aziv AI out.', 'none', 0, true, true],
            ['Pro', 'pro', 'For regular use.', 'monthly', 0, false, false],
            ['Premium', 'premium', 'For heavy use and priority routing.', 'monthly', 0, false, false],
        ];

        foreach ($plans as $index => [$name, $slug, $description, $cycle, $credits, $isFree, $isDefault]) {
            $plan = Plan::firstOrCreate(
                ['slug' => $slug],
                [
                    'name' => $name,
                    'description' => $description,
                    'billing_cycle' => $cycle,
                    'credits_per_period' => $credits,
                    'is_free' => $isFree,
                    // NOT the default yet: a default plan switches metering on
                    // for everybody, and that is the owner's decision to make
                    // when they are ready.
                    'is_default' => false,
                    'is_public' => false,
                    'status' => Plan::STATUS_DRAFT,
                    'sort_order' => $index,
                ],
            );

            $this->planFeatures($plan, $isFree);
        }
    }

    private function planFeatures(Plan $plan, bool $isFree): void
    {
        // Shapes, not ceilings: every one is "unlimited" until an owner enters
        // a number, so a plan published before the limits are set works rather
        // than blocking everyone.
        foreach (['messages_per_day', 'max_attachments', 'conversation_history_days'] as $key) {
            PlanFeature::firstOrCreate(
                ['plan_id' => $plan->getKey(), 'key' => $key],
                ['value' => null, 'limit_type' => PlanFeature::UNLIMITED],
            );
        }

        PlanFeature::firstOrCreate(
            ['plan_id' => $plan->getKey(), 'key' => 'can_pin_model'],
            ['value' => $isFree ? '0' : '1', 'limit_type' => PlanFeature::HARD],
        );
    }
}
