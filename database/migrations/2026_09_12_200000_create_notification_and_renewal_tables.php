<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The notification system (§22) and what manual renewal needs (Addendum D §3).
 *
 * Two features in one migration because they are one delivery: a renewal
 * invoice nobody is told about is not a renewal flow.
 */
return new class extends Migration
{
    public function up(): void
    {
        /**
         * In-app notifications — Laravel's own shape, so the framework's
         * `Notifiable` trait, read/unread handling and eventual push support
         * all work without a translation layer.
         */
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['notifiable_type', 'notifiable_id', 'read_at'], 'notifications_unread_index');
        });

        /**
         * The wording an owner controls (§22).
         *
         * A row is an OVERRIDE, not the only copy: every event ships with a
         * default in `NotificationEvent`, so the platform sends correct email
         * on a database where nobody has edited anything. Deleting a row
         * restores the shipped wording rather than silencing the event.
         */
        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            $table->string('event_key', 64)->index();
            // mail | database
            $table->string('channel', 16);
            $table->string('subject')->nullable();
            $table->text('body')->nullable();
            // The placeholders this event provides, copied from the registry
            // so the editing screen can list them without loading code.
            $table->json('variables')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // One override per event per channel. Two would mean the wording
            // sent depended on row order.
            $table->unique(['event_key', 'channel']);
        });

        /**
         * What was sent, to whom, and whether it arrived.
         *
         * NEVER THE BODY. A delivery record is an audit trail, and an audit
         * trail holding a rendered email would hold a payment link, an
         * amount, and whatever else the template carried.
         */
        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('event_key', 64)->index();
            $table->string('channel', 16);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // queued | sent | failed | suppressed
            $table->string('status', 16)->default('queued')->index();
            // What it was about, so support can answer "did they get the
            // renewal notice for invoice X?" without reading the email.
            $table->string('reference_type', 64)->nullable();
            $table->string('reference_id', 64)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            // Scrubbed before it is written. A mail driver's exception can
            // carry a connection string.
            $table->string('error', 500)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
        });

        /**
         * Announcements, offers, maintenance notices and system updates (§22).
         *
         * AN ANNOUNCEMENT IS A MESSAGE, NOT A BANNER. Aziv AI already has a
         * banner: `banners` owns the strip at the top of a page, its priority
         * and its per-browser dismissal (Phase 2). Adding a second strip would
         * mean two audiences, two dismissals and two ways to push page content
         * below the fold on a phone.
         *
         * What §22 asks for and a banner cannot do is REACH PEOPLE: an
         * audience defined by their subscription, delivered through the one
         * notifier, in the app and — if the owner chooses — by email. So this
         * is composed, targeted and sent, and it leaves the same delivery
         * records as every other notification.
         *
         * The body is stored as text and always ESCAPED on output. Free HTML
         * would be free HTML in an inbox, which is how a link comes to say one
         * thing and go somewhere else.
         */
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('title', 190);
            $table->text('body');
            // info | success | warning | critical — a token name, never a colour
            $table->string('level', 16)->default('info');
            // everyone | subscribers | trialing | past_due | plan
            $table->string('audience', 24)->default('everyone');
            $table->foreignId('audience_plan_id')->nullable()->constrained('subscription_plans')->nullOnDelete();
            // draft | scheduled | sent — §22 asks for a status, and three is
            // all there is: being written, waiting for its time, gone.
            $table->string('status', 16)->default('draft')->index();
            // In the app always; by email only when the owner says so. Mailing
            // every customer is not something to do by forgetting a checkbox.
            $table->boolean('send_email')->default(false);
            $table->timestamp('send_at')->nullable()->index();
            // Set BEFORE sending starts, so a second press cannot mail
            // everybody twice.
            $table->timestamp('sent_at')->nullable();
            $table->unsignedInteger('recipient_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        /**
         * What makes a renewal invoice issue exactly once.
         *
         * The guard is the UNIQUE INDEX, not a check in PHP: the scheduler can
         * overlap with an administrator pressing "send the renewal now", and
         * two processes can both read "no invoice yet" in the same
         * millisecond. Only the database can serialise them.
         */
        Schema::table('invoices', function (Blueprint $table) {
            $table->timestamp('renewal_period_start')->nullable()->after('subscription_id');
            $table->unique(['subscription_id', 'renewal_period_start'], 'invoices_renewal_period_unique');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // The foreign key on subscription_id leans on this index, so MySQL
            // refuses to drop it while the constraint stands. Lift the
            // constraint, drop the index, put the constraint back.
            $table->dropForeign(['subscription_id']);
            $table->dropUnique('invoices_renewal_period_unique');
            $table->dropColumn('renewal_period_start');
            $table->foreign('subscription_id')->references('id')->on('subscriptions')->nullOnDelete();
        });

        Schema::dropIfExists('announcements');
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('notification_templates');
        Schema::dropIfExists('notifications');
    }
};
