<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * File analysis and knowledge bases (§17, Phase 8a).
 *
 * BUILT ON PHASE 1, NOT BESIDE IT. `files` already owns validation, storage,
 * scanning and the access log; nothing here duplicates any of that. A
 * `document` is what happens to a file AFTER it has been accepted: extracted,
 * chunked, embedded and made searchable.
 *
 * D-03 — vector storage starts in MySQL. The embedding lives in a BLOB beside
 * its chunk and similarity is computed in PHP, behind a `VectorStore`
 * contract, so moving to pgvector or a dedicated store later is a new
 * implementation of one interface rather than a rewrite.
 */
return new class extends Migration
{
    public function up(): void
    {
        /**
         * A knowledge base is a SEARCHABLE COLLECTION, and who may search it
         * is part of what it is (§17: "sensitive file access must follow
         * role-based permissions").
         *
         * Two scopes, deliberately different in kind:
         *  - PERSONAL: created by a customer, visible only to them. Their own
         *    documents, their own questions.
         *  - SHARED: created by an administrator and granted to named
         *    customers or to whole plans. This is the one that carries risk,
         *    so a grant is always explicit — there is no "everyone" value.
         */
        Schema::create('knowledge_bases', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            // personal | shared
            $table->string('scope', 16)->default('personal')->index();
            // The customer who owns a personal base. Null for a shared one.
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);

            // --- retrieval settings (§17: "configure retrieval settings") ---
            // How many chunks reach the model. More is not better: every chunk
            // spends context the conversation itself needs.
            $table->unsignedSmallInteger('top_k')->default(5);
            // Below this similarity a chunk is not evidence, it is noise. A
            // low bar is how a document about cats answers a question about
            // tax with confident nonsense.
            $table->decimal('min_score', 4, 3)->default(0.250);
            $table->unsignedSmallInteger('chunk_size')->default(1200);
            $table->unsignedSmallInteger('chunk_overlap')->default(180);
            // Null lets the router choose by capability, like everything else.
            $table->foreignId('embedding_model_id')->nullable()->constrained('ai_models')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'scope']);
        });

        /**
         * Who may use a SHARED base.
         *
         * By named customer or by whole plan — nothing else. An audience
         * expressed as a free query would be a way to hand somebody else's
         * documents to anyone.
         */
        Schema::create('knowledge_base_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('knowledge_base_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained('subscription_plans')->cascadeOnDelete();
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['knowledge_base_id', 'user_id'], 'kb_grant_user_unique');
            $table->unique(['knowledge_base_id', 'plan_id'], 'kb_grant_plan_unique');
        });

        /**
         * A file that has been taken into a knowledge base.
         *
         * Separate from `files` because the two have different lifetimes and
         * different owners: the same uploaded file can be ingested into more
         * than one base, and deleting a document must not delete the upload
         * out from under the other one.
         */
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('knowledge_base_id')->constrained()->cascadeOnDelete();
            $table->foreignId('file_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 255);

            // pending | extracting | extracted | embedding | ready | failed
            // Every one of these is shown to the customer. "Processing" with
            // no detail is what makes people upload the same file four times.
            $table->string('status', 16)->default('pending')->index();
            $table->string('extractor_key', 32)->nullable();
            $table->unsignedInteger('character_count')->default(0);
            $table->unsignedInteger('chunk_count')->default(0);
            $table->unsignedInteger('token_estimate')->default(0);
            // Plain words, safe to show. Never an exception message.
            $table->string('failure_reason', 255)->nullable();
            $table->timestamp('extracted_at')->nullable();
            $table->timestamp('embedded_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // One file is ingested into one base once. Re-uploading the same
            // document is a re-index, not a second copy competing with itself
            // in every search result.
            $table->unique(['knowledge_base_id', 'file_id'], 'documents_kb_file_unique');
            $table->index(['knowledge_base_id', 'status']);
        });

        /**
         * The searchable unit.
         *
         * THE EMBEDDING IS A BLOB OF FLOAT32, not JSON. A 1536-dimension
         * vector is 6 KB packed and about 20 KB as JSON text, and a knowledge
         * base is tens of thousands of these — the difference decides whether
         * a search reads 60 MB or 200 MB off disk.
         *
         * `dimensions` travels with it so unpacking is safe, and
         * `embedding_model` so a base whose model changed can be found and
         * re-embedded rather than silently comparing vectors from two
         * different spaces, which produces plausible-looking nonsense.
         */
        Schema::create('document_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            // Denormalised on purpose: every search filters by base first, and
            // joining to documents for it would be the whole query's cost.
            $table->foreignId('knowledge_base_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('ordinal');
            $table->mediumText('content');
            $table->unsignedInteger('token_estimate')->default(0);
            // Where it came from in the original — a citation a person can
            // check, which is what separates an answer from an assertion.
            $table->string('locator', 120)->nullable();

            $table->binary('embedding')->nullable();
            $table->string('embedding_model', 120)->nullable();
            $table->unsignedSmallInteger('dimensions')->nullable();
            // Identical text does not need embedding twice, and re-indexing a
            // document that barely changed should not cost a full pass.
            $table->string('checksum', 64)->index();

            $table->timestamps();

            $table->unique(['document_id', 'ordinal']);
            $table->index(['knowledge_base_id', 'document_id']);
        });

        /** Which knowledge bases a conversation is allowed to draw on. */
        Schema::create('conversation_knowledge_base', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->foreignId('knowledge_base_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['conversation_id', 'knowledge_base_id'], 'conversation_kb_unique');
        });

        /**
         * Why the model was told what it was told.
         *
         * The same reason `routing_logs` exists: "why did it answer that?"
         * needs an answer six months later. Chunk ids and scores, never the
         * chunk text — the text is one join away and duplicating it here would
         * double the storage of the largest table in the system.
         */
        Schema::create('retrieval_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->nullable()->constrained('chat_messages')->cascadeOnDelete();
            $table->foreignId('knowledge_base_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('candidates')->default(0);
            $table->unsignedInteger('returned')->default(0);
            $table->decimal('top_score', 6, 5)->nullable();
            $table->json('chunk_ids')->nullable();
            $table->json('scores')->nullable();
            $table->unsignedInteger('took_ms')->default(0);
            $table->timestamp('retrieved_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retrieval_logs');
        Schema::dropIfExists('conversation_knowledge_base');
        Schema::dropIfExists('document_chunks');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('knowledge_base_grants');
        Schema::dropIfExists('knowledge_bases');
    }
};
