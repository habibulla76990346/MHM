<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Routing decisions, usage and cost (blueprint §13, §14, §21).
 *
 * The shape that matters: `routing_logs.candidates` records EVERY model
 * considered and why each was rejected. Six months from now, "why did this go
 * to the expensive model?" is answered by a row rather than by speculation —
 * and the margin figures in §21 are computed from recorded fact rather than
 * estimated after the event.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('routing_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('request_type', 24)->default('chat');
            $table->string('routing_mode', 24)->index();
            // What the request genuinely needed, resolved in stage 1.
            $table->json('capability_required')->nullable();
            // Every candidate and the reason it was rejected or chosen.
            $table->json('candidates')->nullable();
            $table->foreignId('selected_provider_id')->nullable()->constrained('ai_providers')->nullOnDelete();
            $table->foreignId('selected_model_id')->nullable()->constrained('ai_models')->nullOnDelete();
            $table->unsignedTinyInteger('fallback_depth')->default(0);
            $table->string('decision_reason', 190)->nullable();
            $table->timestamp('decided_at')->index();
            $table->timestamps();

            $table->index(['user_id', 'decided_at']);
        });

        Schema::create('api_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('provider_id')->nullable()->constrained('ai_providers')->nullOnDelete();
            $table->foreignId('model_id')->nullable()->constrained('ai_models')->nullOnDelete();
            // Which key served it — for per-key quota awareness (Rule 7).
            // Never the key itself.
            $table->foreignId('credential_id')->nullable()->constrained('ai_provider_credentials')->nullOnDelete();
            $table->foreignId('routing_log_id')->nullable()->constrained('routing_logs')->nullOnDelete();
            $table->string('capability', 32)->default('chat');
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            // A class, never a provider's words.
            $table->string('error_class', 48)->nullable();
            // NATIVE currency, recorded alongside (§13). Converting at write
            // time would freeze one rate into history; converting at read time
            // with today's rate would rewrite history every time it moves.
            $table->decimal('provider_cost', 16, 10)->default(0);
            $table->string('provider_currency', 3)->default('USD');
            // What the customer was charged, in credits.
            $table->decimal('credit_cost', 14, 6)->default(0);
            $table->timestamp('occurred_at')->index();
            $table->timestamps();

            $table->index(['user_id', 'occurred_at']);
            $table->index(['provider_id', 'occurred_at']);
            $table->index(['model_id', 'occurred_at']);
        });

        Schema::create('message_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('chat_messages')->cascadeOnDelete();
            $table->foreignId('usage_log_id')->nullable()->constrained('api_usage_logs')->nullOnDelete();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('credit_cost', 14, 6)->default(0);
            $table->timestamps();

            $table->unique('message_id');
        });

        /**
         * Dated rates (§13).
         *
         * Margin for any period is computed at the rate effective on each
         * usage date, so a figure reported last quarter still reads the same
         * next year. A stale rate is far better than a missing one, so the
         * newest rate at or before a date is always used.
         */
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->string('base_currency', 3);
            $table->string('quote_currency', 3);
            $table->decimal('rate', 18, 8);
            $table->date('effective_on');
            $table->string('source', 48)->default('manual');
            $table->timestamps();

            $table->unique(['base_currency', 'quote_currency', 'effective_on']);
            $table->index(['base_currency', 'quote_currency', 'effective_on']);
        });

        /**
         * Nightly rollup. A dashboard covering a year must not scan the
         * highest-volume table in the system on every page load.
         */
        Schema::create('usage_daily_summaries', function (Blueprint $table) {
            $table->id();
            $table->date('summary_date')->index();
            $table->foreignId('provider_id')->nullable()->constrained('ai_providers')->nullOnDelete();
            $table->foreignId('model_id')->nullable()->constrained('ai_models')->nullOnDelete();
            $table->unsignedInteger('requests')->default(0);
            $table->unsignedInteger('failures')->default(0);
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->decimal('provider_cost', 16, 6)->default(0);
            $table->string('provider_currency', 3)->default('USD');
            // The cost converted at the rate that applied ON summary_date, so
            // the number never moves afterwards.
            $table->decimal('provider_cost_base', 16, 6)->default(0);
            $table->decimal('credit_cost', 16, 6)->default(0);
            $table->unsignedInteger('p50_latency_ms')->nullable();
            $table->timestamps();

            $table->unique(['summary_date', 'provider_id', 'model_id'], 'usage_daily_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_daily_summaries');
        Schema::dropIfExists('exchange_rates');
        Schema::dropIfExists('message_usage');
        Schema::dropIfExists('api_usage_logs');
        Schema::dropIfExists('routing_logs');
    }
};
