<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a non-text call actually consumed (§16, §18).
 *
 * `api_usage_logs` counted tokens, because until now every call was made of
 * them. An image call has none and an audio call has none, so both would
 * record a row whose cost is right and whose quantity is zero — and a cost
 * screen showing "0 tokens, ₹4.20" reads as a bug rather than as an image.
 *
 * Two nullable columns rather than a generic "units" pair: images and seconds
 * are different quantities, they are priced differently, and a single column
 * holding either would need a second column to say which — at which point it
 * is two columns with an extra step.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_usage_logs', function (Blueprint $table) {
            $table->unsignedInteger('images')->default(0)->after('total_tokens');
            $table->decimal('audio_seconds', 12, 2)->default(0)->after('images');
        });
    }

    public function down(): void
    {
        Schema::table('api_usage_logs', function (Blueprint $table) {
            $table->dropColumn(['images', 'audio_seconds']);
        });
    }
};
