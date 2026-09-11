<?php

namespace App\Domains\AI\Routing;

/**
 * The eight ways a request can choose a model (blueprint §14).
 *
 * Each is a different answer to "what matters most on this request?", and the
 * owner or the customer picks — Aziv AI does not decide on their behalf. A
 * platform that always optimised for cost would quietly serve worse answers;
 * one that always optimised for quality would quietly spend more.
 */
class RoutingMode
{
    public const AUTO = 'auto';

    public const BEST_QUALITY = 'best_quality';

    public const FASTEST = 'fastest';

    public const LOWEST_COST = 'lowest_cost';

    public const FREE_ONLY = 'free_only';

    public const ADMIN_PREFERRED = 'admin_preferred';

    public const SPECIFIC_PROVIDER = 'specific_provider';

    public const SPECIFIC_MODEL = 'specific_model';

    /** @return array<string, array{label: string, description: string}> */
    public static function all(): array
    {
        return [
            self::AUTO => [
                'label' => 'Automatic',
                'description' => 'Balances how well a provider is performing, how fast it is, what it costs and your own priority order.',
            ],
            self::BEST_QUALITY => [
                'label' => 'Best quality',
                'description' => 'Your own quality ranking first, then whichever provider is healthiest.',
            ],
            self::FASTEST => [
                'label' => 'Fastest',
                'description' => 'The lowest typical response time actually observed, not an advertised figure.',
            ],
            self::LOWEST_COST => [
                'label' => 'Lowest cost',
                'description' => 'The cheapest model that can still do the job.',
            ],
            self::FREE_ONLY => [
                'label' => 'Free only',
                'description' => 'Only providers you have marked as free-tier accounts.',
            ],
            self::ADMIN_PREFERRED => [
                'label' => 'Your preferred order',
                'description' => 'Strictly the provider priority you set, ignoring cost and speed.',
            ],
            self::SPECIFIC_PROVIDER => [
                'label' => 'One provider',
                'description' => 'Pinned to a single provider. Its models only.',
            ],
            self::SPECIFIC_MODEL => [
                'label' => 'One model',
                'description' => 'Pinned to a single model. Never substituted, even if it fails.',
            ],
        ];
    }

    /** @return array<int, string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function exists(string $mode): bool
    {
        return isset(self::all()[$mode]);
    }

    public static function label(string $mode): string
    {
        return self::all()[$mode]['label'] ?? $mode;
    }

    /**
     * Modes where substitution is forbidden.
     *
     * "One model" means one model. Silently answering with a different one
     * would make the setting a lie, so a pinned model that fails returns an
     * error rather than quietly becoming something else.
     */
    public static function forbidsFallback(string $mode): bool
    {
        return $mode === self::SPECIFIC_MODEL;
    }
}
