<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * File storage (Owner Addendum H).
 *
 * `stored_name` is generated; `original_name` is display text only and is
 * never used to build a filesystem path. Files live on a PRIVATE disk outside
 * the web root and are served through an authorising controller — see
 * docs/18-upload-security.md §1 US-5, US-6, US-9.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('files', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();          // public identifier — never a sequential id
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('disk', 32)->default('private');
            $table->string('path');
            $table->string('stored_name');            // generated
            $table->string('original_name');          // display only, sanitised on output
            $table->string('detected_mime', 128);     // from file content
            $table->string('declared_mime', 128)->nullable(); // what the browser claimed
            $table->string('extension', 16);
            $table->unsignedBigInteger('size_bytes');
            $table->string('checksum', 64)->index();
            $table->string('purpose', 32)->default('attachment');
            $table->string('scan_status', 16)->default('skipped');
            $table->string('scan_verdict', 16)->nullable();
            $table->string('scanner_key', 32)->nullable();
            $table->timestamp('quarantined_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'created_at']);
        });

        Schema::create('file_scan_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('file_id')->constrained()->cascadeOnDelete();
            $table->string('scanner_key', 32);
            $table->string('verdict', 16);
            $table->text('details')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamp('scanned_at');
            $table->timestamps();
        });

        Schema::create('file_access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('file_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 24);
            $table->string('ip', 45)->nullable();
            $table->timestamp('occurred_at');

            $table->index(['file_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_access_logs');
        Schema::dropIfExists('file_scan_results');
        Schema::dropIfExists('files');
    }
};
