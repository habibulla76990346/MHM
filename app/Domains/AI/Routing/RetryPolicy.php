<?php

namespace App\Domains\AI\Routing;

use App\Domains\AI\Support\ErrorClass;

/**
 * STAGE 4 (blueprint §14): when to try the same provider again.
 *
 * WHAT IS RETRIED, and what is not, is the whole substance here. Retrying a
 * rejected API key does not make it valid; it just spends the customer's
 * patience and the owner's rate limit before failing identically. So only
 * TRANSIENT failures are retried:
 *
 *   retried      timeout, rate limit, 5xx, connection reset
 *   NOT retried  bad key, not entitled, invalid request, content filtered,
 *                out of provider credit, model not found
 *
 * BACKOFF DOUBLES AND CARRIES JITTER. Without jitter, every request that
 * failed at the same moment retries at the same moment, and a provider
 * recovering from a blip is immediately knocked over again by the thundering
 * herd its own outage created.
 *
 * A provider that says `Retry-After` is obeyed. It knows when it will be ready
 * and guessing against it is how an account gets rate-limited harder.
 */
class RetryPolicy
{
    /** However long a provider asks for, do not hold a customer past this. */
    private const MAX_WAIT_MS = 20_000;

    public function shouldRetry(string $errorClass, int $attempt): bool
    {
        if ($attempt >= $this->maxRetries()) {
            return false;
        }

        return ErrorClass::isRetryable($errorClass);
    }

    /**
     * How long to wait before attempt N.
     *
     * @param  int|null  $retryAfterSeconds  what the provider asked for, if anything
     */
    public function delayMs(int $attempt, ?int $retryAfterSeconds = null): int
    {
        if ($retryAfterSeconds !== null && $retryAfterSeconds > 0) {
            // The provider's own instruction wins over our arithmetic.
            return min($retryAfterSeconds * 1000, self::MAX_WAIT_MS);
        }

        $base = $this->baseMs() * (2 ** max(0, $attempt - 1));

        // Full jitter across the window, rather than a fixed fraction: this is
        // what actually de-synchronises a herd, where "base plus a little
        // random" leaves everyone still clustered.
        $jittered = random_int((int) ($base * 0.5), (int) $base);

        return min($jittered, self::MAX_WAIT_MS);
    }

    /** Parse a Retry-After header, which may be seconds or an HTTP date. */
    public function retryAfterFrom(?string $header): ?int
    {
        if ($header === null || trim($header) === '') {
            return null;
        }

        $header = trim($header);

        if (ctype_digit($header)) {
            return (int) $header;
        }

        $timestamp = strtotime($header);

        return $timestamp === false ? null : max(0, $timestamp - time());
    }

    public function baseMs(): int
    {
        return max(50, (int) settings('routing.retry_base_ms'));
    }

    public function maxRetries(): int
    {
        return max(0, (int) settings('routing.max_retries'));
    }

    public function maxFallbackDepth(): int
    {
        return max(0, (int) settings('routing.max_fallback_depth'));
    }
}
