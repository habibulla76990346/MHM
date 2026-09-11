<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chat (blueprint §15).
 *
 * The shape that matters here is `parent_message_id` / `regenerated_from_id`.
 * §15 requires "regenerate" to preserve the original answer, so a regenerated
 * reply is a NEW row pointing back at the one it replaced — never an update
 * that overwrites what the customer already saw.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personas', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('description')->nullable();
            // The system prompt an administrator writes. Never typed by a
            // customer: a customer-supplied system prompt is how a product's
            // guardrails get talked away.
            $table->text('system_prompt');
            $table->boolean('is_default')->default(false);
            $table->json('plan_restrictions')->nullable();
            $table->string('status', 16)->default('active')->index();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('chat_conversations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->foreignId('persona_id')->nullable()->constrained('personas')->nullOnDelete();
            $table->foreignId('pinned_model_id')->nullable()->constrained('ai_models')->nullOnDelete();
            // auto | specific_model — the rest of the modes arrive with the
            // router in Phase 5.
            $table->string('routing_mode', 24)->default('auto');
            $table->boolean('is_archived')->default(false)->index();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // The chat list is ordered by this on every page load.
            $table->index(['user_id', 'last_message_at']);
        });

        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->string('role', 16);
            $table->longText('content')->nullable();
            // pending | streaming | complete | stopped | failed
            $table->string('status', 16)->default('complete')->index();
            $table->foreignId('provider_id')->nullable()->constrained('ai_providers')->nullOnDelete();
            $table->foreignId('model_id')->nullable()->constrained('ai_models')->nullOnDelete();
            $table->foreignId('parent_message_id')->nullable()->constrained('chat_messages')->nullOnDelete();
            // Set on a REPLACEMENT answer, pointing at the one it replaced.
            // Both rows survive, so "regenerate" never destroys history.
            $table->foreignId('regenerated_from_id')->nullable()->constrained('chat_messages')->nullOnDelete();
            // A class, never a provider's words.
            $table->string('error_class', 48)->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
        });

        Schema::create('chat_message_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('chat_messages')->cascadeOnDelete();
            $table->foreignId('file_id')->constrained('files')->cascadeOnDelete();
            $table->string('kind', 24)->default('image');
            $table->timestamps();

            $table->unique(['message_id', 'file_id']);
        });

        Schema::create('message_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('chat_messages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->tinyInteger('rating');
            $table->string('comment', 500)->nullable();
            $table->timestamps();

            // One opinion per person per message; changing your mind updates it.
            $table->unique(['message_id', 'user_id']);
        });

        Schema::create('conversation_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_public')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_shares');
        Schema::dropIfExists('message_feedback');
        Schema::dropIfExists('chat_message_attachments');
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_conversations');
        Schema::dropIfExists('personas');
    }
};
