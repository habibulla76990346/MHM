<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Diagnostic history (Owner Addendum G §8, Phase 9).
 *
 * WHY HISTORY AT ALL, when the checks run in under a second. Because the two
 * things an owner actually needs are not answered by a live run:
 *
 *   - "WHEN did this break?" A red row tells you the state now. Yesterday's
 *     run tells you the deploy that caused it.
 *   - "Tell me when something CHANGES." Alerting on every red would send the
 *     same email every night until it is fixed, which is how people learn to
 *     filter these into a folder they never open. Alerting on a TRANSITION —
 *     green to red, or red back to green — is a message worth reading.
 *
 * NOTHING HERE HOLDS A SECRET. `CheckResult` scrubs at construction, so what
 * is written was already redacted before it reached this layer — there is no
 * path that could store an unscrubbed value even by mistake.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diagnostic_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // install | manual | scheduled | event
            $table->string('trigger', 16)->index();
            $table->string('deployment_mode', 16)->nullable();

            // The worst status in the run, so a list of runs is scannable
            // without joining every result.
            $table->string('overall_status', 8)->nullable();
            $table->unsignedSmallInteger('green')->default(0);
            $table->unsignedSmallInteger('yellow')->default(0);
            $table->unsignedSmallInteger('red')->default(0);
            $table->unsignedSmallInteger('grey')->default(0);

            $table->foreignId('run_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['created_at']);
        });

        Schema::create('diagnostic_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('diagnostic_runs')->cascadeOnDelete();

            $table->string('check_key', 64)->index();
            $table->string('title', 160);
            $table->string('category', 32);
            $table->string('status', 8)->index();
            $table->string('severity', 16);
            $table->string('responsibility', 24);

            // Already scrubbed by CheckResult before it got here.
            $table->text('technical_reason')->nullable();
            $table->text('recommended_action')->nullable();
            $table->text('admin_action')->nullable();
            $table->boolean('requires_hosting_support')->default(false);
            $table->text('support_wording')->nullable();
            $table->string('log_reference', 190)->nullable();

            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamp('checked_at')->nullable();

            $table->index(['run_id', 'status']);
        });

        /**
         * The last state each check was in, so a change can be recognised.
         *
         * ONE ROW PER CHECK, updated in place. The alternative — reading the
         * previous run and diffing — breaks the first time a check is added,
         * removed, or skipped because it was not applicable, and gets slower
         * as the history grows.
         */
        Schema::create('diagnostic_baselines', function (Blueprint $table) {
            $table->id();
            $table->string('check_key', 64)->unique();
            $table->string('last_status', 8);
            $table->string('last_severity', 16);
            // How long it has been like this. A check red for a fortnight is a
            // different conversation from one red since this morning.
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamp('changed_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            // Set when a transition was announced, so a retry of the same
            // transition cannot send the message twice.
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diagnostic_results');
        Schema::dropIfExists('diagnostic_baselines');
        Schema::dropIfExists('diagnostic_runs');
    }
};
