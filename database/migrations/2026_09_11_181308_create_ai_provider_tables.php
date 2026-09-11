<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The universal AI provider layer (blueprint §10–§14).
 *
 * The shape here is what makes Rule 5 possible: no model name and no provider
 * name appears in code. The application asks the database which models support
 * a required capability, and the answer is rows an administrator controls.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_providers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('slug')->unique();
            // Which adapter class handles this provider. NOT a brand name in
            // code — the registry maps this to a class, and two of the five
            // adapters need no code at all to add a provider.
            $table->string('adapter_type', 48)->index();
            $table->string('api_base_url')->nullable();
            $table->string('api_format', 32)->default('openai');
            $table->string('auth_method', 32)->default('bearer');
            // active | disabled | maintenance
            $table->string('status', 16)->default('disabled')->index();
            $table->unsignedSmallInteger('priority')->default(100);
            $table->string('region', 32)->nullable();
            // free | paid | enterprise | unknown — affects routing in Free Only mode
            $table->string('account_class', 16)->default('unknown');
            $table->boolean('maintenance_mode')->default(false);
            $table->unsignedSmallInteger('timeout_seconds')->default(60);
            $table->unsignedTinyInteger('max_retries')->default(2);
            $table->json('settings')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('ai_provider_credentials', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('provider_id')->constrained('ai_providers')->cascadeOnDelete();
            $table->string('label');
            // ENCRYPTED at rest (Rule 6). longText because ciphertext is much
            // larger than the key, and an over-tight column would truncate it
            // into something that decrypts to nothing.
            $table->longText('credential');
            $table->longText('extra_config')->nullable();
            // The last 4 characters, stored separately so the admin screen can
            // identify a key WITHOUT ever decrypting it to display it.
            $table->string('hint', 8)->nullable();
            $table->string('status', 16)->default('active')->index();
            $table->unsignedSmallInteger('priority')->default(100);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->string('last_verify_result', 32)->nullable();
            $table->string('quota_note')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        /**
         * Per-key usage, so limits are RESPECTED rather than evaded (Rule 7).
         * Multiple credentials exist for genuine rotation and redundancy; this
         * table is what lets the system stay inside a provider's terms instead
         * of quietly working around them.
         */
        Schema::create('credential_usage_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credential_id')->constrained('ai_provider_credentials')->cascadeOnDelete();
            $table->timestamp('window_start')->index();
            $table->unsignedBigInteger('request_count')->default(0);
            $table->unsignedBigInteger('token_count')->default(0);
            $table->timestamps();

            $table->unique(['credential_id', 'window_start']);
        });

        Schema::create('ai_provider_budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained('ai_providers')->cascadeOnDelete();
            $table->string('period', 16)->default('monthly');
            $table->decimal('budget_amount', 14, 4);
            $table->string('currency', 3)->default('USD');
            $table->decimal('spent_amount', 14, 4)->default(0);
            $table->unsignedTinyInteger('threshold_percent')->default(80);
            // warn | block — what happens when the cap is reached
            $table->string('action_on_breach', 16)->default('warn');
            $table->timestamp('period_started_at')->nullable();
            $table->timestamps();

            $table->unique(['provider_id', 'period']);
        });

        Schema::create('ai_provider_budget_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained('ai_provider_budgets')->cascadeOnDelete();
            $table->unsignedTinyInteger('threshold_hit');
            $table->timestamp('notified_at')->nullable();
            $table->string('action_taken', 32)->nullable();
            $table->timestamps();
        });

        /**
         * The catalog (§11). Rule 5: no model name is ever hard-coded, so this
         * table is the only place the application learns what exists.
         */
        Schema::create('ai_models', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('provider_id')->constrained('ai_providers')->cascadeOnDelete();
            // What the provider calls it. Sent on the wire, never shown raw.
            $table->string('model_identifier');
            $table->string('display_name');
            $table->text('description')->nullable();
            // stable | preview | experimental | deprecated | disabled
            $table->string('status', 16)->default('stable')->index();
            // text | multimodal | image | audio | embedding
            $table->string('modality', 24)->default('text');
            $table->unsignedInteger('context_window')->nullable();
            $table->unsignedInteger('max_output_tokens')->nullable();
            // A model discovered by a sync arrives DISABLED. A provider adding
            // a model must never start costing an owner money before they have
            // seen it and decided.
            $table->boolean('is_enabled')->default(false)->index();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->unsignedTinyInteger('quality_rank')->default(50);
            $table->timestamp('discovered_at')->nullable();
            // synced | manual — a synced model may be re-synced; a manual one
            // is the administrator's own and is never overwritten.
            $table->string('source', 16)->default('manual');
            $table->timestamp('last_seen_at')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['provider_id', 'model_identifier']);
        });

        Schema::create('provider_health_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained('ai_providers')->cascadeOnDelete();
            $table->foreignId('model_id')->nullable()->constrained('ai_models')->nullOnDelete();
            $table->timestamp('checked_at')->index();
            $table->boolean('success');
            $table->unsignedInteger('latency_ms')->nullable();
            // A CLASS, never the provider's raw message: raw text from an API
            // can echo back a request that contained a credential.
            $table->string('error_class', 48)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->timestamps();

            $table->index(['provider_id', 'checked_at']);
        });

        Schema::create('provider_circuit_state', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->unique()->constrained('ai_providers')->cascadeOnDelete();
            $table->string('state', 16)->default('closed');
            $table->unsignedSmallInteger('failure_count')->default(0);
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('next_probe_at')->nullable();
            $table->boolean('forced_open')->default(false);
            $table->timestamps();
        });

        Schema::create('ai_model_capabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('model_id')->constrained('ai_models')->cascadeOnDelete();
            $table->string('capability', 32)->index();
            $table->boolean('is_supported')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['model_id', 'capability']);
        });

        Schema::create('ai_model_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('model_id')->constrained('ai_models')->cascadeOnDelete();
            // per_1k_input | per_1k_output | per_image | per_second | per_request
            $table->string('unit', 24);
            // What the AI company charges Aziv AI.
            $table->decimal('provider_cost', 14, 8)->default(0);
            $table->string('currency', 3)->default('USD');
            // What Aziv AI charges the customer, in credits. The gap is margin.
            $table->decimal('credit_cost', 14, 6)->default(0);
            // Dated, so a usage record from March is costed at March's price.
            // Without this, editing a price silently rewrites the profitability
            // of the entire history.
            $table->timestamp('effective_from')->index();
            $table->timestamp('effective_until')->nullable();
            $table->timestamps();

            $table->index(['model_id', 'unit', 'effective_from']);
        });

        Schema::create('ai_model_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained('ai_providers')->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->string('status', 16)->default('running')->index();
            $table->unsignedSmallInteger('models_added')->default(0);
            $table->unsignedSmallInteger('models_updated')->default(0);
            $table->unsignedSmallInteger('models_deprecated')->default(0);
            $table->text('error_message')->nullable();
            // A digest, not the response: a provider's raw payload is large and
            // can echo request content back.
            $table->string('raw_response_digest', 64)->nullable();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('custom_provider_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained('ai_providers')->cascadeOnDelete();
            $table->string('capability', 32);
            $table->string('http_method', 8)->default('POST');
            $table->string('endpoint_path');
            $table->json('request_template')->nullable();
            $table->json('response_mapping')->nullable();
            $table->string('stream_format', 24)->nullable();
            $table->json('headers_template')->nullable();
            $table->timestamps();

            $table->unique(['provider_id', 'capability']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_provider_mappings');
        Schema::dropIfExists('ai_model_sync_logs');
        Schema::dropIfExists('ai_model_prices');
        Schema::dropIfExists('ai_model_capabilities');
        Schema::dropIfExists('provider_circuit_state');
        Schema::dropIfExists('provider_health_logs');
        Schema::dropIfExists('ai_models');
        Schema::dropIfExists('ai_provider_budget_alerts');
        Schema::dropIfExists('ai_provider_budgets');
        Schema::dropIfExists('credential_usage_counters');
        Schema::dropIfExists('ai_provider_credentials');
        Schema::dropIfExists('ai_providers');
    }
};
