<?php

namespace App\Domains\Diagnostics\Support;

/**
 * Last line of defence for the "no secrets in diagnostics" rule.
 *
 * The PRIMARY defence is structural: checks never receive credential values.
 * This exists because exception messages and driver errors can still carry a
 * connection string or a bearer token, and the diagnostic report is designed
 * to be forwarded to a hosting provider.
 */
final class Redactor
{
    private const PATTERNS = [
        // bearer / authorization headers
        '/\b(bearer|authorization)\s*[:=]\s*\S+/i',
        // provider-style keys: sk-..., rzp_live_..., pk_test_..., AIza...
        '/\b(?:sk|pk|rk|api|key)[-_][A-Za-z0-9_\-]{12,}/i',
        '/\brzp_(?:live|test)_[A-Za-z0-9]+/i',
        '/\bAIza[0-9A-Za-z_\-]{20,}/',
        // env-style assignments of anything secret-shaped
        '/\b([A-Z_]*(?:PASSWORD|SECRET|TOKEN|KEY|DSN)[A-Z_]*)\s*=\s*\S+/i',
        // URLs carrying credentials
        '/\b[a-z][a-z0-9+.\-]*:\/\/[^\s:@\/]+:[^\s@\/]+@/i',
        // long opaque blobs
        '/\b[A-Za-z0-9\/+]{40,}={0,2}\b/',
    ];

    public static function scrub(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        foreach (self::PATTERNS as $pattern) {
            $text = preg_replace($pattern, '[redacted]', $text) ?? $text;
        }

        return $text;
    }
}
