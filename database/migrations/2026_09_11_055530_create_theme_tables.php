<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Theme engine (blueprint §5, owner decision D-07).
 *
 * Colours live here, never in a stylesheet. `scope` carries D-07: the SAME
 * engine drives the customer application and the Admin Panel, as two token
 * sets rather than two systems.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('themes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('description')->nullable();
            $table->boolean('is_builtin')->default(false);
            $table->boolean('is_active')->default(false);
            $table->boolean('supports_dark')->default(true);
            $table->foreignId('base_theme_id')->nullable()->constrained('themes')->nullOnDelete();
            // Permission-gated and sanitised before it is ever rendered (§5).
            $table->longText('custom_css')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('published_at')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active']);
        });

        Schema::create('theme_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('theme_id')->constrained()->cascadeOnDelete();
            // customer | admin  — the D-07 two-token-set design
            $table->string('scope', 16)->default('customer');
            // light | dark
            $table->string('mode', 8)->default('light');
            $table->string('token_group', 32)->index();
            $table->string('token_key', 64);
            $table->string('token_value', 255);
            $table->timestamps();

            $table->unique(['theme_id', 'scope', 'mode', 'token_key']);
        });

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
            // logo_primary, logo_dark, favicon, app_icon, avatar_default, …
            $table->string('purpose', 48)->index();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_assets');
        Schema::dropIfExists('theme_tokens');
        Schema::dropIfExists('themes');
    }
};
