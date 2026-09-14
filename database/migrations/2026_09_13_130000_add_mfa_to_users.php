<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional MFA for privileged administrators (§23, Phase 9).
 *
 * TOTP, AND NOTHING THAT NEEDS A THIRD PARTY. Six digits from an app the
 * administrator already has, verified with arithmetic on this server. SMS
 * would mean an account with a provider, a per-message cost, and a channel
 * that is routinely taken over by asking a phone company nicely; an emailed
 * code protects an account whose password reset goes to the same inbox.
 *
 * THE SECRET IS ENCRYPTED, like every other credential in this platform, and
 * it is `$hidden` on the model. Anybody holding it can generate valid codes
 * for ever, which makes it worth exactly as much as the password.
 *
 * RECOVERY CODES ARE HASHED, not encrypted, and that is deliberate: nothing
 * ever needs to READ one back, only to check whether a code somebody typed
 * matches. A hash cannot be turned back into a code by whoever gets the
 * database, and a used one is deleted rather than marked.
 *
 * `mfa_confirmed_at` is separate from `mfa_secret` on purpose. A secret that
 * exists but was never confirmed is somebody who scanned the code and closed
 * the tab — locking them out on that basis would be locking them out of their
 * own platform over an unfinished form.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('mfa_secret')->nullable()->after('password');
            $table->timestamp('mfa_confirmed_at')->nullable()->after('mfa_secret');
            // Hashed, one per line. A JSON column would invite reading them
            // back as a list, and they exist to be checked, never shown.
            $table->text('mfa_recovery_codes')->nullable()->after('mfa_confirmed_at');
            $table->timestamp('mfa_last_used_at')->nullable()->after('mfa_recovery_codes');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['mfa_secret', 'mfa_confirmed_at', 'mfa_recovery_codes', 'mfa_last_used_at']);
        });
    }
};
