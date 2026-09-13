<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Voice: speech to text and text to speech (§18, Phase 8c).
 *
 * ONE TABLE FOR BOTH DIRECTIONS. A transcription and a synthesis are the same
 * shape of thing — a customer, a model, some audio, some text, a duration, a
 * cost and a status — and they are asked the same questions: how much audio
 * did this account use today, what did it cost, and why did that one fail.
 * Two tables would mean answering each of those twice and getting a different
 * answer.
 *
 * THE TRANSCRIPT IS NOT AUDIO AND IS KEPT DIFFERENTLY. What a customer said
 * becomes a chat message, which is theirs and lives as long as their
 * conversation does. The RECORDING is a large file of somebody's voice, which
 * is a different kind of thing to keep: it has its own retention setting and
 * the default deletes it in a week. Losing the audio loses nothing the
 * customer can see.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_jobs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            // transcription | speech
            $table->string('kind', 16)->index();

            $table->foreignId('model_id')->nullable()->constrained('ai_models')->nullOnDelete();

            // The recording for a transcription; the synthesised audio for
            // speech. Null while queued, and null again once retention has
            // swept the audio away — the row survives, because the cost did.
            $table->foreignId('file_id')->nullable()->constrained('files')->nullOnDelete();

            // What this was for, when it was part of a conversation. Null for
            // a recording made in the composer before any message exists.
            $table->foreignId('message_id')->nullable()->constrained('chat_messages')->nullOnDelete();

            // The transcript, or the text that was read aloud. Kept for both:
            // it is what the customer asked for and what they can check.
            $table->longText('text')->nullable();
            $table->string('language', 12)->nullable();

            // What was billed against. Providers charge audio by DURATION and
            // speech by CHARACTERS, so both are recorded rather than one being
            // derived from the other with a guess.
            $table->decimal('seconds', 10, 2)->default(0);
            $table->unsignedInteger('characters')->default(0);

            // queued | working | completed | failed
            $table->string('status', 16)->default('queued')->index();
            // Scrubbed before it is written: a provider's raw error may echo
            // the failing request, and that request carried the key.
            $table->string('failure_reason', 500)->nullable();

            // Stored, not recomputed: editing a price must never rewrite what
            // somebody already paid.
            $table->decimal('credit_cost', 18, 6)->default(0);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            // When the AUDIO is deleted. The row and its text outlive it.
            $table->timestamp('expires_at')->nullable()->index();

            $table->timestamps();
            $table->softDeletes();

            // "How much audio has this account used today?" — the quota
            // question, asked before every recording.
            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_jobs');
    }
};
