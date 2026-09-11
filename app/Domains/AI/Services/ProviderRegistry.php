<?php

namespace App\Domains\AI\Services;

use App\Domains\AI\Adapters\CustomHttpAdapter;
use App\Domains\AI\Adapters\GeminiAdapter;
use App\Domains\AI\Adapters\OpenAiAdapter;
use App\Domains\AI\Adapters\OpenAiCompatibleAdapter;
use App\Domains\AI\Contracts\ProviderAdapter;
use App\Domains\AI\Models\AiProvider;

/**
 * Maps a provider's `adapter_type` to the class that speaks its API
 * (blueprint §12).
 *
 * This is the ONLY place in the application where a provider's shape is named.
 * Everything above it asks for capabilities and receives a normalised answer,
 * which is what makes adding a provider a configuration task — and why two of
 * the adapters below cover most of the market between them without any code.
 *
 * Later phases register purpose-built adapters here (OpenAI and Gemini in
 * Phase 4, Anthropic in Phase 7). Registration is the whole integration: no
 * other file changes.
 */
class ProviderRegistry
{
    /** @var array<string, class-string<ProviderAdapter>> */
    private array $adapters = [];

    public function __construct()
    {
        $this->register(OpenAiAdapter::KEY, OpenAiAdapter::class);
        $this->register(GeminiAdapter::KEY, GeminiAdapter::class);
        $this->register(OpenAiCompatibleAdapter::KEY, OpenAiCompatibleAdapter::class);
        $this->register(CustomHttpAdapter::KEY, CustomHttpAdapter::class);
    }

    /** @param class-string<ProviderAdapter> $class */
    public function register(string $key, string $class): void
    {
        $this->adapters[$key] = $class;
    }

    public function has(string $key): bool
    {
        return isset($this->adapters[$key]);
    }

    /**
     * The choices an administrator sees, with what each one is for.
     *
     * @return array<string, string>
     */
    public function options(): array
    {
        $labels = [
            OpenAiAdapter::KEY => 'OpenAI',
            GeminiAdapter::KEY => 'Google Gemini',
            OpenAiCompatibleAdapter::KEY => 'OpenAI-compatible — works with most other providers, no code needed',
            CustomHttpAdapter::KEY => 'Custom API — describe the request and response yourself',
        ];

        $options = [];

        foreach (array_keys($this->adapters) as $key) {
            $options[$key] = $labels[$key] ?? $key;
        }

        return $options;
    }

    /**
     * An adapter bound to this provider.
     *
     * Returns null rather than throwing for an unknown type: a provider row
     * can outlive the adapter that served it — a downgrade, a removed
     * integration — and the Admin Panel has to stay usable so the owner can
     * see the problem and fix it.
     */
    public function for(AiProvider $provider): ?ProviderAdapter
    {
        $class = $this->adapters[$provider->adapter_type] ?? null;

        if (! $class) {
            return null;
        }

        return app($class)->forProvider($provider);
    }

    /** @return array<int, string> */
    public function keys(): array
    {
        return array_keys($this->adapters);
    }

    /**
     * Sensible starting settings for the providers Aziv AI ships adapters for.
     *
     * This is convenience, not configuration-in-code: an owner can change
     * every one of these afterwards, and a provider with no preset is added
     * exactly the same way. It exists so that adding OpenAI does not begin
     * with hunting for a base URL in someone's documentation.
     *
     * @return array<string, array{name: string, api_base_url: string, auth_method: string, key_source: string}>
     */
    public function presets(): array
    {
        return [
            OpenAiAdapter::KEY => [
                'name' => 'OpenAI',
                'api_base_url' => OpenAiAdapter::DEFAULT_BASE_URL,
                'auth_method' => 'bearer',
                'key_source' => 'Create a key at platform.openai.com → API keys.',
            ],
            GeminiAdapter::KEY => [
                'name' => 'Google Gemini',
                'api_base_url' => GeminiAdapter::DEFAULT_BASE_URL,
                // Gemini takes the key as a query parameter named `key`,
                // not as a bearer token.
                'auth_method' => 'query',
                'key_source' => 'Create a key at aistudio.google.com → Get API key.',
            ],
        ];
    }
}
