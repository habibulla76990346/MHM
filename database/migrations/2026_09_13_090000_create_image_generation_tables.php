<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Image generation (§16, Phase 8b).
 *
 * ONE ROW IS ONE IMAGE, not one request. A customer who asks for four and
 * keeps one thinks about four things, deletes one of them, and regenerates
 * another — so the gallery, deletion, retention and lineage are all per image.
 * A `batch_uuid` keeps the four together for the one screen that cares.
 *
 * THE BYTES ARE A `files` ROW, on the private disk, like every other file in
 * the platform. Nothing here re-implements storage, scanning or the access
 * log; a generated image is a file that happened to arrive from a provider
 * rather than from a browser, and the one thing that differs — that its bytes
 * were never validated as an upload — is handled by `FileStorage::storeGenerated()`
 * with a stricter allowlist than an upload gets.
 *
 * WHAT IS DELIBERATELY NOT A TABLE. "Prompt history" (§16) is a query over
 * this one: the prompts a customer has used ARE their generations, and a
 * second table holding the same strings would be a second thing to keep in
 * step, to delete on request, and to get wrong. Same for the status history —
 * a generation has one status and two timestamps, which answers every question
 * the screen asks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('image_generations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Restricted rather than cascaded: deleting a customer who has
            // spent credits on images is a decision with a paper trail, not a
            // side effect of a checkbox.
            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            // WHICH model made it, kept for the gallery and for "why did this
            // one look different?". Null when the model is later removed from
            // the catalog — the image outlives the row that describes it.
            $table->foreignId('model_id')->nullable()->constrained('ai_models')->nullOnDelete();
            $table->foreignId('routing_log_id')->nullable()->constrained('routing_logs')->nullOnDelete();

            // The image itself. Null while queued, and null again if the
            // customer deletes the file but keeps the prompt.
            $table->foreignId('file_id')->nullable()->constrained('files')->nullOnDelete();

            // Everything in one request shares this, so "show me that set of
            // four" is one index lookup rather than a join on timestamps.
            $table->uuid('batch_uuid')->index();
            $table->unsignedTinyInteger('position')->default(0);

            $table->text('prompt');
            // Some providers rewrite the prompt before generating and tell you
            // what they used. Keeping it is the only way to answer "I asked
            // for a red car and got a blue one".
            $table->text('revised_prompt')->nullable();
            $table->string('negative_prompt', 1000)->nullable();

            // Provider-agnostic words. An adapter maps them onto whatever its
            // provider calls them; nothing above the adapter knows the
            // vocabulary of any one company.
            $table->string('size', 16)->default('1024x1024');
            $table->string('quality', 16)->default('standard');
            $table->string('style', 24)->nullable();

            // queued | generating | completed | failed | cancelled
            $table->string('status', 16)->default('queued')->index();
            // Scrubbed before it is written. A provider's raw error text may
            // echo the failing request, and that request carried the key.
            $table->string('failure_reason', 500)->nullable();

            // What the customer was actually charged, stored rather than
            // recomputed: editing a price must never rewrite what somebody
            // already paid.
            $table->decimal('credit_cost', 18, 6)->default(0);

            // Regeneration (§16). A new row pointing at the one it came from,
            // so both survive and the customer can compare them — the same
            // shape as regenerating a chat reply.
            $table->foreignId('regenerated_from_id')->nullable()
                ->constrained('image_generations')->nullOnDelete();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            // Retention (§16: "admin controls ... retention"). Null means
            // kept until somebody deletes it.
            $table->timestamp('expires_at')->nullable()->index();

            $table->timestamps();
            $table->softDeletes();

            // The gallery: this customer's images, newest first.
            $table->index(['user_id', 'created_at']);
            // The sweeper: everything still working, oldest first.
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('image_generations');
    }
};
