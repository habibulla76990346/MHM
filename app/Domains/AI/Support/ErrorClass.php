<?php

namespace App\Domains\AI\Support;

/**
 * Normalised failure reasons.
 *
 * Every provider words its errors differently; the application needs one
 * vocabulary so the router, the circuit breaker and the diagnostics screen can
 * all reason about "what went wrong" without parsing prose.
 *
 * SECURITY: a provider's raw error text is never stored or shown. Some APIs
 * echo the failing request back in the message, and that request carried a
 * credential. A class plus an HTTP status is enough to act on and cannot leak.
 */
class ErrorClass
{
    public const AUTHENTICATION = 'authentication';

    public const AUTHORISATION = 'authorisation';

    public const RATE_LIMIT = 'rate_limit';

    public const QUOTA_EXCEEDED = 'quota_exceeded';

    public const INVALID_REQUEST = 'invalid_request';

    public const MODEL_NOT_FOUND = 'model_not_found';

    public const CONTEXT_TOO_LONG = 'context_too_long';

    public const CONTENT_FILTERED = 'content_filtered';

    public const TIMEOUT = 'timeout';

    public const NETWORK = 'network';

    public const PROVIDER_ERROR = 'provider_error';

    public const UNKNOWN = 'unknown';

    /**
     * What an administrator should do about it, in plain language
     * (Owner Addendum G: never "Something went wrong").
     *
     * @return array<string, array{label: string, action: string, retryable: bool}>
     */
    public static function all(): array
    {
        return [
            self::AUTHENTICATION => [
                'label' => 'Key rejected',
                'action' => 'The API key is wrong, expired or revoked. Create a new key in the provider\'s dashboard and replace it here.',
                'retryable' => false,
            ],
            self::AUTHORISATION => [
                'label' => 'Not permitted',
                'action' => 'The key is valid but this account is not allowed to use that model. Check the plan or tier on the provider\'s dashboard.',
                'retryable' => false,
            ],
            self::RATE_LIMIT => [
                'label' => 'Too many requests',
                'action' => 'The provider is asking Aziv AI to slow down. It will retry shortly. If it persists, request a higher rate limit from the provider.',
                'retryable' => true,
            ],
            self::QUOTA_EXCEEDED => [
                'label' => 'Out of credit',
                'action' => 'The account with this provider has run out of credit or hit its spending cap. Top it up in the provider\'s dashboard.',
                'retryable' => false,
            ],
            self::INVALID_REQUEST => [
                'label' => 'Request refused',
                'action' => 'The provider rejected the shape of the request. If this is a custom provider, check the request mapping.',
                'retryable' => false,
            ],
            self::MODEL_NOT_FOUND => [
                'label' => 'Model not available',
                'action' => 'That model no longer exists on this provider, or the account cannot reach it. Run Refresh Models.',
                'retryable' => false,
            ],
            self::CONTEXT_TOO_LONG => [
                'label' => 'Conversation too long',
                'action' => 'The conversation exceeded the model\'s limit. A model with a larger context window is needed.',
                'retryable' => false,
            ],
            self::CONTENT_FILTERED => [
                'label' => 'Blocked by the provider',
                'action' => 'The provider\'s own safety filter refused this content. Nothing is wrong with your configuration.',
                'retryable' => false,
            ],
            self::TIMEOUT => [
                'label' => 'No answer in time',
                'action' => 'The provider did not respond within the timeout. Raise the timeout for this provider, or check their status page.',
                'retryable' => true,
            ],
            self::NETWORK => [
                'label' => 'Could not connect',
                'action' => 'Aziv AI could not reach the provider at all. Check outbound HTTPS on System Health — many shared hosts block it.',
                'retryable' => true,
            ],
            self::PROVIDER_ERROR => [
                'label' => 'Provider fault',
                'action' => 'The provider returned an error of its own. Check their status page; this is not a problem with your settings.',
                'retryable' => true,
            ],
            self::UNKNOWN => [
                'label' => 'Unrecognised failure',
                'action' => 'Aziv AI could not classify this failure. The diagnostic reference below identifies the log entry.',
                'retryable' => false,
            ],
        ];
    }

    public static function label(string $class): string
    {
        return self::all()[$class]['label'] ?? $class;
    }

    public static function action(string $class): string
    {
        return self::all()[$class]['action'] ?? self::all()[self::UNKNOWN]['action'];
    }

    public static function isRetryable(string $class): bool
    {
        return self::all()[$class]['retryable'] ?? false;
    }

    /**
     * Map an HTTP status to a class when the body gives nothing better.
     *
     * Deliberately conservative: a wrong guess that says "your key is invalid"
     * sends an owner to regenerate a key that was fine.
     */
    public static function fromHttpStatus(int $status): string
    {
        return match (true) {
            $status === 401 => self::AUTHENTICATION,
            $status === 403 => self::AUTHORISATION,
            $status === 404 => self::MODEL_NOT_FOUND,
            $status === 408 => self::TIMEOUT,
            $status === 422 => self::INVALID_REQUEST,
            $status === 429 => self::RATE_LIMIT,
            $status >= 500 => self::PROVIDER_ERROR,
            $status >= 400 => self::INVALID_REQUEST,
            default => self::UNKNOWN,
        };
    }
}
