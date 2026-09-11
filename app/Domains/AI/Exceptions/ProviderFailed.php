<?php

namespace App\Domains\AI\Exceptions;

use App\Domains\AI\Support\ErrorClass;
use RuntimeException;

/**
 * A normalised provider failure.
 *
 * Carries a CLASS and an HTTP status, never the provider's own message. Some
 * APIs echo the failing request back in their error text, and that request
 * carried a credential — so the raw text is discarded at the boundary rather
 * than filtered later.
 */
class ProviderFailed extends RuntimeException
{
    public function __construct(
        public readonly string $errorClass,
        public readonly ?int $httpStatus = null,
        public readonly int $latencyMs = 0,
        /**
         * What the provider asked us to wait, in seconds, if it said so.
         *
         * Carried here because the boundary is the only place the header
         * still exists: by the time the router decides whether to try again,
         * the response is gone. Obeying it is not politeness — guessing
         * against a provider's own back-off is how an account gets limited
         * harder than it already was.
         */
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct(ErrorClass::label($errorClass));
    }

    public function action(): string
    {
        return ErrorClass::action($this->errorClass);
    }

    public function isRetryable(): bool
    {
        return ErrorClass::isRetryable($this->errorClass);
    }
}
