<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the router needs the chat tables to carry (blueprint §14, §15).
 *
 * `routing_log_id` on a message is the link that makes "why did my answer come
 * from that model?" answerable months later — without it the decision and the
 * thing it produced are two unrelated rows. `fallback_depth` records that a
 * substitution happened at all: an answer produced on the second provider is
 * still a good answer, but an owner seeing many of them has a provider problem
 * to look at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_conversations', function (Blueprint $table) {
            // Phase 4 shipped auto | specific_model. The router adds the rest,
            // and "one provider" needs somewhere to record WHICH provider.
            $table->foreignId('pinned_provider_id')->nullable()->after('pinned_model_id')
                ->constrained('ai_providers')->nullOnDelete();
        });

        Schema::table('chat_messages', function (Blueprint $table) {
            $table->foreignId('routing_log_id')->nullable()->after('model_id')
                ->constrained('routing_logs')->nullOnDelete();
            $table->unsignedTinyInteger('fallback_depth')->default(0)->after('routing_log_id');
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('routing_log_id');
            $table->dropColumn('fallback_depth');
        });

        Schema::table('chat_conversations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pinned_provider_id');
        });
    }
};
