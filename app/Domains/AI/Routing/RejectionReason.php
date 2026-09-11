<?php

namespace App\Domains\AI\Routing;

/**
 * Why a model was not chosen.
 *
 * Every rejection is recorded against the routing decision, so the answer to
 * "why did this request go there?" is a row rather than a reconstruction. The
 * wording is aimed at an owner reading a log, not at a developer reading a
 * stack trace.
 */
class RejectionReason
{
    public const PROVIDER_DISABLED = 'provider_disabled';

    public const PROVIDER_MAINTENANCE = 'provider_maintenance';

    public const MODEL_DISABLED = 'model_disabled';

    public const MODEL_DEPRECATED = 'model_deprecated';

    public const MISSING_CAPABILITY = 'missing_capability';

    public const CIRCUIT_OPEN = 'circuit_open';

    public const BUDGET_EXHAUSTED = 'budget_exhausted';

    public const CONTEXT_TOO_SMALL = 'context_too_small';

    public const NOT_FREE_TIER = 'not_free_tier';

    public const WRONG_PROVIDER = 'wrong_provider';

    public const NO_CREDENTIAL = 'no_credential';

    public const NO_ADAPTER = 'no_adapter';

    public const ALREADY_TRIED = 'already_tried';

    /** @return array<string, string> */
    public static function all(): array
    {
        return [
            self::PROVIDER_DISABLED => 'Its provider is switched off',
            self::PROVIDER_MAINTENANCE => 'Its provider is in maintenance',
            self::MODEL_DISABLED => 'The model is not available to customers',
            self::MODEL_DEPRECATED => 'The model is deprecated',
            self::MISSING_CAPABILITY => 'It cannot do what this request needs',
            self::CIRCUIT_OPEN => 'Its provider is failing and has been taken out of rotation',
            self::BUDGET_EXHAUSTED => 'Its provider has reached the spending cap you set',
            self::CONTEXT_TOO_SMALL => 'The conversation is longer than the model can handle',
            self::NOT_FREE_TIER => 'It is not on a free account, and this request is free-only',
            self::WRONG_PROVIDER => 'This request is pinned to a different provider',
            self::NO_CREDENTIAL => 'Its provider has no API key',
            self::NO_ADAPTER => 'No adapter is installed for its provider type',
            self::ALREADY_TRIED => 'Already tried on this request and failed',
        ];
    }

    public static function explain(string $reason): string
    {
        return self::all()[$reason] ?? $reason;
    }
}
