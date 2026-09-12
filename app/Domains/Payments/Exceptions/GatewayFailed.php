<?php

namespace App\Domains\Payments\Exceptions;

use RuntimeException;

/**
 * A normalised gateway failure.
 *
 * Carries a CLASS, never the gateway's own words. Payment APIs echo the
 * failing request back in their error bodies, and that request carried the
 * merchant secret — so the raw text is discarded at the boundary rather than
 * filtered somewhere downstream.
 */
class GatewayFailed extends RuntimeException
{
    public const AUTHENTICATION = 'authentication';

    public const NOT_PERMITTED = 'not_permitted';

    public const INVALID_REQUEST = 'invalid_request';

    public const DECLINED = 'declined';

    public const RATE_LIMIT = 'rate_limit';

    public const TIMEOUT = 'timeout';

    public const NETWORK = 'network';

    public const GATEWAY_ERROR = 'gateway_error';

    public const UNKNOWN = 'unknown';

    /** @return array<string, array{label: string, advice: string}> */
    public static function all(): array
    {
        return [
            self::AUTHENTICATION => [
                'label' => 'Credentials rejected',
                'advice' => 'The key or secret is wrong for this mode. Check them in the gateway\'s dashboard and re-enter them here.',
            ],
            self::NOT_PERMITTED => [
                'label' => 'Not permitted',
                'advice' => 'The credentials are valid but this merchant account is not allowed to do that. Check what the account is activated for.',
            ],
            self::INVALID_REQUEST => [
                'label' => 'Request refused',
                'advice' => 'The gateway rejected the shape of the request — often an unsupported currency or an amount below its minimum.',
            ],
            self::DECLINED => [
                'label' => 'Payment declined',
                'advice' => 'The customer\'s bank declined it. They should try another method.',
            ],
            self::RATE_LIMIT => [
                'label' => 'Too many requests',
                'advice' => 'The gateway is asking us to slow down. It will be retried.',
            ],
            self::TIMEOUT => [
                'label' => 'No response in time',
                'advice' => 'The gateway did not answer. The payment may still have gone through — the reconciliation sweep will settle it.',
            ],
            self::NETWORK => [
                'label' => 'Could not reach the gateway',
                'advice' => 'Outbound HTTPS to the gateway failed. On shared hosting this is often blocked and needs the host to allow it.',
            ],
            self::GATEWAY_ERROR => [
                'label' => 'Gateway error',
                'advice' => 'The gateway reported a problem on its side. Check its status page.',
            ],
            self::UNKNOWN => [
                'label' => 'Unknown problem',
                'advice' => 'Look at the transaction timeline for this payment, and at the gateway\'s dashboard.',
            ],
        ];
    }

    public function __construct(
        public readonly string $errorClass,
        public readonly ?int $httpStatus = null,
        public readonly int $latencyMs = 0,
    ) {
        parent::__construct(self::label($errorClass));
    }

    public static function label(string $class): string
    {
        return self::all()[$class]['label'] ?? 'Payment problem';
    }

    public static function advice(string $class): string
    {
        return self::all()[$class]['advice'] ?? self::all()[self::UNKNOWN]['advice'];
    }

    public function action(): string
    {
        return self::advice($this->errorClass);
    }

    public static function fromHttpStatus(int $status): string
    {
        return match (true) {
            $status === 401 => self::AUTHENTICATION,
            $status === 403 => self::NOT_PERMITTED,
            $status === 429 => self::RATE_LIMIT,
            $status === 400 || $status === 404 || $status === 422 => self::INVALID_REQUEST,
            $status >= 500 => self::GATEWAY_ERROR,
            default => self::UNKNOWN,
        };
    }
}
