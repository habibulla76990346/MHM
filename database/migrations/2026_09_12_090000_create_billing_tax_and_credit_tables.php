<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plans, credits, tax and invoices (§19, §20, Owner Addenda D and F).
 *
 * Four shapes here carry the weight, and each exists to stop a specific way
 * money goes wrong:
 *
 *  - `credit_ledger` is APPEND-ONLY. A balance that can be edited is a balance
 *    nobody can audit; corrections are new rows, never updates.
 *  - `subscription_periods` has UNIQUE (subscription_id, period_start). Two
 *    webhooks for one payment cannot both activate the same period, which is
 *    how a customer ends up with double credits.
 *  - `invoice_tax_lines` is a SNAPSHOT, not a reference. Editing a tax rate
 *    must never change an invoice already issued — the requirement Addendum F
 *    exists to keep.
 *  - Money is stored with the currency beside it, always. There is no "the"
 *    currency in this system.
 *
 * MONEY IS decimal(18, 6). Not a float, which cannot represent 0.1; and not
 * two fixed decimals, which breaks the zero-decimal and three-decimal
 * currencies `currencies.decimal_places` exists to describe. Six is enough for
 * per-unit credit pricing without rounding at rest.
 */
return new class extends Migration
{
    public function up(): void
    {
        /* ------------------------------------------------ money as data -- */

        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->string('code', 3)->unique();
            $table->string('name');
            $table->string('symbol', 8)->default('');
            // Not every currency has two. Storing money at a fixed two
            // decimals silently breaks JPY (0) and KWD (3), so the formatter
            // reads this rather than assuming.
            $table->unsignedTinyInteger('decimal_places')->default(2);
            // e.g. "{symbol}{amount}" or "{amount} {code}" — placement differs
            // by market and is not a code decision.
            $table->string('display_format', 32)->default('{symbol}{amount}');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_base')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('tax_jurisdictions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('country', 2)->index();
            // Null means "the whole country". A state-level jurisdiction wins
            // over a country-level one through `priority`.
            $table->string('state', 64)->nullable();
            $table->boolean('is_domestic')->default(true);
            $table->unsignedSmallInteger('priority')->default(100);
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        Schema::create('countries', function (Blueprint $table) {
            $table->id();
            $table->string('code', 2)->unique();
            $table->string('name');
            $table->string('default_currency', 3)->nullable();
            // India needs a state for place of supply; many countries do not.
            $table->boolean('requires_state')->default(false);
            $table->boolean('is_billing_enabled')->default(false);
            $table->foreignId('tax_jurisdiction_id')->nullable()
                ->constrained('tax_jurisdictions')->nullOnDelete();
            $table->timestamps();
        });

        /* ------------------------------------------------------- the tax -- */

        /**
         * One row. A singleton rather than settings keys because these fields
         * are copied onto every invoice as a group, and a half-written set of
         * settings would produce an invoice with a legal name but no address.
         */
        Schema::create('tax_settings', function (Blueprint $table) {
            $table->id();
            $table->string('legal_name')->nullable();
            $table->text('address_lines')->nullable();
            $table->string('city')->nullable();
            $table->string('state', 64)->nullable();
            $table->string('postal_code', 24)->nullable();
            $table->string('country', 2)->nullable();
            // GSTIN, VAT number, ABN — the label is the admin's, not the code's.
            $table->string('tax_registration_number', 64)->nullable();
            $table->string('registration_type', 48)->nullable();
            $table->string('default_place_of_supply', 64)->nullable();
            $table->string('service_code', 24)->nullable();
            // OFF until an administrator turns it on and configures it. No rate
            // and no treatment is ever assumed (Addendum F, Rule 1).
            $table->boolean('tax_enabled')->default(false);
            $table->string('pricing_mode', 16)->default('exclusive');
            $table->string('rounding_mode', 16)->default('half_up');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('jurisdiction_id')->constrained('tax_jurisdictions')->cascadeOnDelete();
            // The admin names it. Nothing in code knows what a component is
            // called — that is the whole of Rule 1.
            $table->string('name');
            $table->string('code', 32)->nullable();
            $table->decimal('rate_percent', 9, 5);
            $table->string('component_type', 32)->nullable();
            $table->string('applies_to', 24)->default('all');
            // Dated, so an invoice is computed from the rates that applied on
            // ITS date and reissues identically forever.
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['jurisdiction_id', 'effective_from']);
        });

        Schema::create('tax_rules', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('jurisdiction_id')->constrained('tax_jurisdictions')->cascadeOnDelete();
            // same_state | different_state | export | customer_registered |
            // customer_unregistered | exempt | always
            $table->string('condition_type', 32);
            $table->json('rate_ids');
            $table->unsignedSmallInteger('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('customer_tax_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('country', 2)->nullable();
            $table->string('state', 64)->nullable();
            $table->text('billing_address')->nullable();
            $table->string('billing_name')->nullable();
            $table->string('postal_code', 24)->nullable();
            $table->string('tax_registration_number', 64)->nullable();
            $table->timestamp('registration_verified_at')->nullable();
            $table->boolean('is_business')->default(false);
            $table->string('exemption_reference', 64)->nullable();
            $table->date('exemption_expires_at')->nullable();
            $table->timestamps();
        });

        /* ----------------------------------------------------- the plans -- */

        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('description')->nullable();
            $table->text('highlights')->nullable();
            // monthly | yearly | lifetime | none (for a free plan)
            $table->string('billing_cycle', 16)->default('monthly');
            $table->unsignedSmallInteger('trial_days')->default(0);
            // The credits a period grants. Zero is legitimate: a plan can sell
            // access to features and no AI usage at all.
            $table->decimal('credits_per_period', 18, 6)->default(0);
            $table->boolean('credits_rollover')->default(false);
            $table->unsignedSmallInteger('credit_expiry_days')->nullable();
            $table->boolean('is_free')->default(false);
            // Exactly one plan is the one new accounts land on.
            $table->boolean('is_default')->default(false);
            $table->boolean('is_public')->default(false);
            $table->string('status', 16)->default('draft')->index();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        /**
         * Limits as ROWS, so a new limit never needs a migration (§19's
         * "fully configurable" would otherwise mean a schema change every time
         * the owner thinks of something).
         */
        Schema::create('plan_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('subscription_plans')->cascadeOnDelete();
            $table->string('key', 64);
            $table->string('value')->nullable();
            // hard stops · soft warns · unlimited ignores the value entirely
            $table->string('limit_type', 16)->default('hard');
            $table->timestamps();

            $table->unique(['plan_id', 'key']);
        });

        /**
         * Deliberate per-market pricing, not a live conversion. ₹1,847.32 looks
         * like a mistake, and it moves daily.
         */
        Schema::create('plan_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('subscription_plans')->cascadeOnDelete();
            $table->string('currency', 3);
            $table->decimal('amount', 18, 6);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['plan_id', 'currency']);
        });

        Schema::create('plan_model_access', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('subscription_plans')->cascadeOnDelete();
            $table->foreignId('ai_model_id')->constrained('ai_models')->cascadeOnDelete();
            $table->boolean('is_allowed')->default(true);
            $table->timestamps();

            $table->unique(['plan_id', 'ai_model_id']);
        });

        Schema::create('plan_provider_access', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('subscription_plans')->cascadeOnDelete();
            $table->foreignId('ai_provider_id')->constrained('ai_providers')->cascadeOnDelete();
            $table->boolean('is_allowed')->default(true);
            $table->timestamps();

            $table->unique(['plan_id', 'ai_provider_id']);
        });

        /* --------------------------------------------- the subscriptions -- */

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('subscription_plans')->restrictOnDelete();
            // trialing | active | past_due | cancelled | expired
            $table->string('status', 16)->default('active')->index();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            // Set when the customer cancels; they keep what they paid for
            // until the period ends. Cancelling is not a refund.
            $table->timestamp('cancel_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('currency', 3)->nullable();
            $table->decimal('amount', 18, 6)->default(0);
            // "Subscription" is not one mechanism in India: a gateway's own
            // recurring API, a UPI Autopay mandate, e-NACH, or invoice-and-pay.
            // Recorded rather than assumed (Addendum D).
            $table->string('renewal_mechanism', 24)->default('manual');
            $table->string('mandate_reference')->nullable();
            // A downgrade is SCHEDULED, not applied: the customer paid for
            // this period and keeps it. Recorded here so the change survives a
            // restart and is visible to support.
            $table->foreignId('pending_plan_id')->nullable()
                ->constrained('subscription_plans')->nullOnDelete();
            $table->timestamp('pending_plan_starts_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        /**
         * THE ACTIVATION GUARD.
         *
         * One row per billing period, unique on (subscription, period start).
         * Two webhooks arriving for the same payment both try to insert it;
         * the second fails on the constraint and grants nothing. Without this
         * a retried webhook is free credits.
         */
        Schema::create('subscription_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->decimal('credits_granted', 18, 6)->default(0);
            $table->string('reference')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();

            $table->unique(['subscription_id', 'period_start']);
        });

        /* --------------------------------------------------- the credits -- */

        /**
         * APPEND-ONLY. Inserted, never updated, never deleted — enforced at the
         * model layer too. `balance_after` makes any row auditable on its own,
         * and makes "the ledger disagrees with the balance" detectable.
         */
        Schema::create('credit_ledger', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // grant | deduction | refund | adjustment | expiry | revocation
            $table->string('entry_type', 24)->index();
            // Signed: a deduction is negative. Summing the column must always
            // equal the cached balance, which is the invariant the tests check.
            $table->decimal('amount', 18, 6);
            $table->decimal('balance_after', 18, 6);
            $table->string('reason');
            $table->string('reference_type', 48)->nullable();
            $table->string('reference_id', 64)->nullable();
            $table->timestamp('expires_at')->nullable();
            // Who did it, for a manual adjustment. Null means the system.
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
        });

        Schema::create('credit_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('confirmed_balance', 18, 6)->default(0);
            // Money promised to calls in flight. Spendable = confirmed - held.
            $table->decimal('held_balance', 18, 6)->default(0);
            $table->timestamps();
        });

        /**
         * Pre-authorisation. A hold is taken BEFORE a provider is called and
         * settled to the real cost afterwards, so a customer cannot start ten
         * expensive calls at once against a balance that covers one.
         */
        Schema::create('credit_holds', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 18, 6);
            // held | settled | released
            $table->string('status', 16)->default('held')->index();
            $table->string('reference_type', 48)->nullable();
            $table->string('reference_id', 64)->nullable();
            $table->decimal('settled_amount', 18, 6)->nullable();
            // A crashed worker must not hold a customer's balance forever.
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        /* -------------------------------------------------- the invoices -- */

        Schema::create('invoice_number_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('key', 32)->unique();
            $table->string('prefix', 24)->default('INV-');
            $table->string('suffix', 24)->default('');
            $table->unsignedBigInteger('current_value')->default(0);
            $table->unsignedTinyInteger('padding')->default(5);
            // never | yearly | monthly | financial_year
            $table->string('reset_policy', 24)->default('financial_year');
            // India's financial year starts in April. A setting, not an
            // assumption.
            $table->unsignedTinyInteger('fy_start_month')->default(4);
            $table->string('format_template')->default('{prefix}{fy}{number}{suffix}');
            $table->string('last_period_key', 24)->nullable();
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            // Allocated only when the invoice is ISSUED, so a failed payment
            // never consumes a number and leaves a gap in the sequence.
            $table->string('number', 64)->nullable()->unique();
            // draft | issued | paid | void
            $table->string('status', 16)->default('draft')->index();
            $table->decimal('subtotal', 18, 6)->default(0);
            $table->decimal('discount_total', 18, 6)->default(0);
            $table->decimal('tax_total', 18, 6)->default(0);
            $table->decimal('total', 18, 6)->default(0);
            $table->string('currency', 3);
            $table->decimal('exchange_rate_used', 18, 8)->nullable();
            $table->decimal('base_total', 18, 6)->nullable();

            // --- SNAPSHOTTED AT ISSUE. Copied, never referenced, so a later
            // change to the business address or the customer's details cannot
            // rewrite a document already sent.
            $table->string('supplier_legal_name')->nullable();
            $table->text('supplier_address')->nullable();
            $table->string('supplier_tax_number', 64)->nullable();
            $table->string('customer_name')->nullable();
            $table->text('customer_address')->nullable();
            $table->string('customer_country', 2)->nullable();
            $table->string('customer_state', 64)->nullable();
            $table->string('customer_tax_number', 64)->nullable();
            $table->string('place_of_supply', 64)->nullable();
            $table->string('service_code', 24)->nullable();
            $table->string('pricing_mode', 16)->nullable();
            $table->boolean('is_export')->default(false);
            $table->string('tax_note')->nullable();

            $table->timestamp('issued_at')->nullable()->index();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'issued_at']);
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->string('description');
            $table->decimal('quantity', 18, 6)->default(1);
            $table->decimal('unit_amount', 18, 6)->default(0);
            $table->decimal('discount_amount', 18, 6)->default(0);
            $table->decimal('line_total', 18, 6)->default(0);
            $table->string('service_code', 24)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        /**
         * The frozen tax computation. Names and rates are COPIES — the rate row
         * they came from may be edited or deleted tomorrow and this document
         * must still read exactly as it did when it was sent.
         */
        Schema::create('invoice_tax_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->string('component_name');
            $table->string('component_code', 32)->nullable();
            $table->decimal('rate_percent', 9, 5);
            $table->decimal('taxable_amount', 18, 6);
            $table->decimal('tax_amount', 18, 6);
            $table->string('jurisdiction_name')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        /**
         * The only way to correct an issued invoice. Accounting does not allow
         * an edit, and neither does this schema.
         */
        Schema::create('credit_notes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->string('number', 64)->unique();
            $table->string('reason');
            $table->decimal('amount', 18, 6);
            $table->decimal('tax_amount', 18, 6)->default(0);
            $table->string('currency', 3);
            // The invoice's tax lines as they stood, so the credit note is
            // self-contained even if the invoice is later archived.
            $table->json('tax_snapshot')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at');
            $table->timestamps();
        });

        /* --------------------------------------------------- promotions -- */

        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('code', 48)->unique();
            $table->string('description')->nullable();
            // percentage | fixed | credits
            $table->string('type', 16)->default('percentage');
            $table->decimal('value', 18, 6);
            $table->string('currency', 3)->nullable();
            $table->unsignedInteger('max_redemptions')->nullable();
            $table->unsignedInteger('redeemed_count')->default(0);
            $table->unsignedInteger('max_per_user')->default(1);
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->json('plan_restrictions')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('coupon_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained('coupons')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reference_type', 48)->nullable();
            $table->string('reference_id', 64)->nullable();
            $table->decimal('amount_discounted', 18, 6)->default(0);
            $table->timestamp('redeemed_at');
            $table->timestamps();

            $table->index(['coupon_id', 'user_id']);
        });
    }

    public function down(): void
    {
        foreach ([
            'coupon_redemptions', 'coupons', 'credit_notes', 'invoice_tax_lines',
            'invoice_lines', 'invoices', 'invoice_number_sequences',
            'credit_holds', 'credit_balances', 'credit_ledger',
            'subscription_periods', 'subscriptions',
            'plan_provider_access', 'plan_model_access', 'plan_prices',
            'plan_features', 'subscription_plans',
            'customer_tax_profiles', 'tax_rules', 'tax_rates', 'tax_settings',
            'countries', 'tax_jurisdictions', 'currencies',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
