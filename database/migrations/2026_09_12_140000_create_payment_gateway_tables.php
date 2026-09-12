<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payments, as a registry of adapters rather than one integration
 * (Owner Addendum D).
 *
 * The owner's governing constraint: *"Do not hard-code Razorpay-specific logic
 * into subscriptions, plans, invoices or transactions."* So a gateway is a ROW
 * with an adapter class attached, and everything upstream holds a gateway id
 * and calls an interface.
 *
 * FOUR IDEMPOTENCY GUARANTEES LIVE IN THIS SCHEMA, because money is where
 * "probably only once" costs real money:
 *
 *   payment_webhook_events   unique (gateway, event id) — a replay is a no-op
 *   payments                 unique idempotency_key — a double-submitted
 *                            checkout reuses one payment
 *   subscription_periods     already unique (subscription, period start)
 *   credit_ledger            entries keyed to the payment they came from
 *
 * THREE AMOUNTS ON EVERY PAYMENT (Addendum F §5.1): a merchant account in
 * India settles in INR whatever the customer was shown, so presentment,
 * settlement and base are different numbers and conflating them produces books
 * that never balance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_gateways', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('key', 48)->unique();
            $table->string('name');
            $table->string('adapter_class');
            $table->string('status', 16)->default('disabled')->index();
            $table->boolean('is_default')->default(false);
            $table->unsignedSmallInteger('priority')->default(100);
            // sandbox | live. Separate credentials per mode, and switching is
            // a deliberate, audited action.
            $table->string('mode', 16)->default('sandbox');
            $table->json('supported_countries')->nullable();
            $table->json('supported_currencies')->nullable();
            // Verified against the gateway's own documentation when the
            // adapter is written, then stored — never assumed from a table in
            // a document that will age.
            $table->json('capabilities')->nullable();
            $table->string('checkout_mode', 16)->default('redirect');
            $table->boolean('maintenance_mode')->default(false);
            $table->string('api_base_url')->nullable();
            $table->unsignedSmallInteger('timeout_seconds')->default(30);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('payment_gateway_credentials', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('gateway_id')->constrained('payment_gateways')->cascadeOnDelete();
            $table->string('mode', 16);
            $table->string('label')->nullable();
            // ENCRYPTED and $hidden on the model. Full account access lives in
            // here; it is never rendered, never serialised and never logged.
            $table->text('credentials');
            $table->text('webhook_secret')->nullable();
            // The publishable identifier is DIFFERENT in kind: it identifies
            // the merchant to the gateway's own JavaScript, is meant to be
            // visible, and authorises nothing on its own.
            $table->string('publishable_key')->nullable();
            // Last few characters only, so the panel can show WHICH key is in
            // place without showing the key.
            $table->string('hint', 12)->nullable();
            $table->string('status', 16)->default('active')->index();
            $table->timestamp('last_verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['gateway_id', 'mode']);
        });

        Schema::create('payment_gateway_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gateway_id')->constrained('payment_gateways')->cascadeOnDelete();
            // subscription | one_time | credit_topup
            $table->string('payment_type', 24)->index();
            $table->string('country', 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->foreignId('plan_id')->nullable()->constrained('subscription_plans')->nullOnDelete();
            $table->unsignedSmallInteger('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained('subscription_plans')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('gateway_id')->nullable()->constrained('payment_gateways')->nullOnDelete();
            // BOTH identifiers, always. Support has an Aziv reference, the
            // gateway dashboard has its own, and reconciliation has to move
            // between them.
            $table->string('gateway_payment_id')->nullable()->index();
            $table->string('gateway_order_id')->nullable()->index();
            $table->string('mode', 16)->default('sandbox');
            $table->string('purpose', 24)->default('subscription');
            // A double-submitted checkout reuses the same payment rather than
            // creating a second one and charging twice.
            $table->string('idempotency_key', 96)->unique();

            // What the customer saw and agreed to.
            $table->decimal('presentment_amount', 18, 6);
            $table->string('presentment_currency', 3);
            // What actually landed, once the gateway reports it.
            $table->decimal('settlement_amount', 18, 6)->nullable();
            $table->string('settlement_currency', 3)->nullable();
            // Converted for reporting, at the rate that applied on the day.
            $table->decimal('base_amount', 18, 6)->nullable();
            $table->decimal('exchange_rate_used', 18, 8)->nullable();

            // created | pending | paid | failed | refunded | partially_refunded
            $table->string('status', 24)->default('created')->index();
            $table->string('failure_code', 64)->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->foreignId('gateway_id')->nullable()->constrained('payment_gateways')->nullOnDelete();
            $table->string('gateway_transaction_id')->nullable();
            $table->string('gateway_reference')->nullable();
            // created | redirected | returned | webhook | reconciled | refunded
            $table->string('type', 32)->index();
            $table->decimal('amount', 18, 6)->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('status', 24)->nullable();
            // Kept for support and dispute work. Scrubbed of anything that
            // looks like a card number or a secret before it is written.
            $table->json('raw_payload')->nullable();
            $table->timestamps();
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('payment_id')->constrained('payments')->restrictOnDelete();
            $table->foreignId('gateway_id')->nullable()->constrained('payment_gateways')->nullOnDelete();
            $table->string('gateway_refund_id')->nullable()->index();
            $table->decimal('amount', 18, 6);
            $table->string('currency', 3);
            $table->string('status', 24)->default('pending')->index();
            $table->string('reason');
            $table->decimal('credits_revoked', 18, 6)->default(0);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        /**
         * The replay guard.
         *
         * A gateway retries a webhook until it is acknowledged, and a customer
         * refreshing a return page produces another. Unique on (gateway,
         * event id) means the second delivery inserts nothing and grants
         * nothing.
         */
        Schema::create('payment_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('gateway_id')->constrained('payment_gateways')->cascadeOnDelete();
            $table->string('event_id', 191);
            $table->string('event_type', 96)->nullable();
            $table->json('raw_payload')->nullable();
            $table->boolean('signature_valid')->default(false);
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->string('result', 191)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamps();

            $table->unique(['gateway_id', 'event_id']);
            $table->index(['gateway_id', 'signature_valid']);
        });

        Schema::create('gateway_health_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gateway_id')->constrained('payment_gateways')->cascadeOnDelete();
            $table->timestamp('checked_at')->index();
            $table->boolean('success')->default(true);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('error_class', 48)->nullable();
            $table->timestamps();
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreignId('gateway_id')->nullable()->after('mandate_reference')
                ->constrained('payment_gateways')->nullOnDelete();
            $table->string('gateway_subscription_id')->nullable()->after('gateway_id');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('payment_id')->nullable()->after('subscription_id')
                ->constrained('payments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $t) => $t->dropConstrainedForeignId('payment_id'));

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('gateway_id');
            $table->dropColumn('gateway_subscription_id');
        });

        foreach ([
            'gateway_health_logs', 'payment_webhook_events', 'refunds',
            'payment_transactions', 'payments', 'payment_gateway_rules',
            'payment_gateway_credentials', 'payment_gateways',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
