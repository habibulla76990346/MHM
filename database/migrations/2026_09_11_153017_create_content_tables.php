<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Content management (blueprint §7, and decision D-11 for navigation).
 *
 * The through-line: everything an owner would otherwise ask a developer to
 * change is a row here. Page copy, announcement scheduling, FAQ ordering,
 * navigation labels and destinations, and which features are switched on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_pages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('slug')->unique();
            $table->string('title');
            // draft | published | scheduled. Scheduling is a published_at in
            // the future rather than a separate flag, so there is one answer
            // to "is this live right now?".
            $table->string('status', 16)->default('draft')->index();
            $table->boolean('is_system')->default(false);
            $table->boolean('show_in_footer')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->json('seo')->nullable();
            $table->timestamp('published_at')->nullable()->index();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('content_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained('content_pages')->cascadeOnDelete();
            // hero | features | steps | faq | cta | richtext | logos
            $table->string('type', 32)->index();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_visible')->default(true);
            // Shape depends on the type; SectionType declares each one, so the
            // editor and the renderer agree on what a payload contains.
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['page_id', 'sort_order']);
        });

        Schema::create('banners', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('title');
            $table->text('body')->nullable();
            // Maps to a status token, never to a colour: a banner must follow
            // the theme like everything else.
            $table->string('variant', 16)->default('info');
            $table->string('cta_label')->nullable();
            $table->string('cta_url')->nullable();
            // Higher wins when several are live at once.
            $table->unsignedSmallInteger('priority')->default(0)->index();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            // everyone | guests | customers | admins
            $table->string('audience', 16)->default('everyone')->index();
            $table->boolean('is_dismissible')->default(true);
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('faqs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('question');
            $table->text('answer');
            $table->string('category', 64)->default('general')->index();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_published')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('navigation_menus', function (Blueprint $table) {
            $table->id();
            $table->string('key', 48)->unique();
            $table->string('name');
            $table->string('location', 48)->index();
            // The bottom bar's 4-item cap is a CONSEQUENCE of the 44px touch
            // minimum at 320px, not a style preference — so it is stored with
            // the menu and enforced, not left to whoever edits it.
            $table->unsignedTinyInteger('max_items')->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        Schema::create('navigation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_id')->constrained('navigation_menus')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('navigation_items')->cascadeOnDelete();
            $table->string('key', 48);
            $table->string('label');
            $table->string('icon', 48)->nullable();
            // route | page | external
            $table->string('destination_type', 16)->default('route');
            $table->string('route_name')->nullable();
            $table->foreignId('page_id')->nullable()->constrained('content_pages')->nullOnDelete();
            $table->string('url')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_visible')->default(true);
            // Which device classes show this item: mobile, tablet, desktop.
            $table->json('device_visibility')->nullable();
            // HIDES a link. Never grants entry — the destination's own policy
            // still decides, and the editor says so.
            $table->string('permission')->nullable();
            $table->string('feature_flag_key')->nullable();
            $table->timestamps();

            $table->index(['menu_id', 'sort_order']);
            $table->unique(['menu_id', 'key']);
        });

        Schema::create('feature_flags', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->boolean('is_enabled')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_flags');
        Schema::dropIfExists('navigation_items');
        Schema::dropIfExists('navigation_menus');
        Schema::dropIfExists('faqs');
        Schema::dropIfExists('banners');
        Schema::dropIfExists('content_sections');
        Schema::dropIfExists('content_pages');
    }
};
