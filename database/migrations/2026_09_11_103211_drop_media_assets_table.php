<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops `media_assets`.
 *
 * It was created earlier in Phase 2, before a close look at the Phase 1
 * `files` table. That table already carries everything a brand asset needs —
 * checksum, dimensions, purpose, owner — AND it is the only path through
 * `UploadValidator` and the scanner, so it is where all nine of Owner
 * Addendum H's upload controls actually live.
 *
 * A second, parallel media table would be a second place for upload security
 * to be got wrong, and the first time the two drifted the weaker one would be
 * the one an administrator used. Brand assets are `files` rows with a
 * `brand_*` purpose instead.
 *
 * No data is lost: nothing ever wrote to this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('media_assets');
    }

    public function down(): void
    {
        Schema::create('media_assets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('disk', 32)->default('private');
            $table->string('path');
            $table->string('stored_name');
            $table->string('original_name');
            $table->string('mime_type', 128);
            $table->string('extension', 16);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('checksum', 64)->index();
            $table->string('alt_text')->nullable();
            $table->string('purpose', 48)->index();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }
};
