<?php

namespace App\Domains\Identity\Services;

use Illuminate\Validation\Rules\Password;

/**
 * The password policy is admin-configurable (blueprint §8), so it is built
 * from settings at validation time rather than hard-coded.
 */
class PasswordPolicy
{
    public static function rule(): Password
    {
        $rule = Password::min((int) settings('auth.password_min_length'));

        if (settings('auth.password_require_mixed_case')) {
            $rule = $rule->mixedCase();
        }

        if (settings('auth.password_require_number')) {
            $rule = $rule->numbers();
        }

        if (settings('auth.password_require_symbol')) {
            $rule = $rule->symbols();
        }

        // Always on: a password appearing in a known breach corpus is unsafe
        // regardless of how many character classes it contains. Degrades
        // gracefully to a length check when the service is unreachable, which
        // matters on hosts that block outbound HTTPS.
        return $rule->uncompromised();
    }

    /** Human-readable summary for the signup form, built from the same settings. */
    public static function describe(): string
    {
        $parts = ['at least '.settings('auth.password_min_length').' characters'];

        if (settings('auth.password_require_mixed_case')) {
            $parts[] = 'upper and lower case';
        }
        if (settings('auth.password_require_number')) {
            $parts[] = 'a number';
        }
        if (settings('auth.password_require_symbol')) {
            $parts[] = 'a symbol';
        }

        return ucfirst(implode(', ', $parts)).'.';
    }
}
