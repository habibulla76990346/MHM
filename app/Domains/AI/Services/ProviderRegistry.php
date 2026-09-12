<?php

namespace App\Domains\AI\Services;

use App\Domains\AI\Adapters\AnthropicAdapter;
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
        $this->register(AnthropicAdapter::KEY, AnthropicAdapter::class);
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
            AnthropicAdapter::KEY => 'Anthropic',
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
     * Known providers an owner can start from, with the settings that are
     * facts rather than choices.
     *
     * CONVENIENCE, NOT CONFIGURATION-IN-CODE. Every value here can be changed
     * afterwards, and a provider with no preset is added in exactly the same
     * way — this exists so that adding one does not begin with hunting for a
     * base URL in someone else's documentation.
     *
     * MOST OF THIS LIST NEEDS NO ADAPTER. Everything marked
     * `openai_compatible` is served by one class, which is the entire point of
     * §12: adding the next provider that copies OpenAI's shape is a row, not a
     * release. No model identifier appears anywhere in it (Rule 5) — the
     * catalog is read from the provider once a key is in place.
     *
     * @return array<string, array{name: string, adapter_type: string, api_base_url: string, auth_method: string, key_source: string, note?: string}>
     */
    public function presets(): array
    {
        return [
            'openai' => [
                'name' => 'OpenAI',
                'adapter_type' => OpenAiAdapter::KEY,
                'api_base_url' => OpenAiAdapter::DEFAULT_BASE_URL,
                'auth_method' => 'bearer',
                'key_source' => 'Create a key at platform.openai.com → API keys.',
            ],
            'anthropic' => [
                'name' => 'Anthropic',
                'adapter_type' => AnthropicAdapter::KEY,
                'api_base_url' => AnthropicAdapter::DEFAULT_BASE_URL,
                // Its own header, plus a version header the adapter sets
                // itself — neither is something an owner should have to know.
                'auth_method' => 'header',
                'key_source' => 'Create a key at console.anthropic.com → API keys.',
            ],
            'gemini' => [
                'name' => 'Google Gemini',
                'adapter_type' => GeminiAdapter::KEY,
                'api_base_url' => GeminiAdapter::DEFAULT_BASE_URL,
                // Gemini takes the key as a query parameter named `key`,
                // not as a bearer token.
                'auth_method' => 'query',
                'key_source' => 'Create a key at aistudio.google.com → Get API key.',
            ],

            // --- Served by the one compatible adapter. No code, ever. ------
            'deepseek' => [
                'name' => 'DeepSeek',
                'adapter_type' => OpenAiCompatibleAdapter::KEY,
                'api_base_url' => 'https://api.deepseek.com/v1',
                'auth_method' => 'bearer',
                'key_source' => 'Create a key at platform.deepseek.com → API keys.',
            ],
            'mistral' => [
                'name' => 'Mistral',
                'adapter_type' => OpenAiCompatibleAdapter::KEY,
                'api_base_url' => 'https://api.mistral.ai/v1',
                'auth_method' => 'bearer',
                'key_source' => 'Create a key at console.mistral.ai → API keys.',
            ],
            'groq' => [
                'name' => 'Groq',
                'adapter_type' => OpenAiCompatibleAdapter::KEY,
                'api_base_url' => 'https://api.groq.com/openai/v1',
                'auth_method' => 'bearer',
                'key_source' => 'Create a key at console.groq.com → API keys.',
            ],
            'openrouter' => [
                'name' => 'OpenRouter',
                'adapter_type' => OpenAiCompatibleAdapter::KEY,
                'api_base_url' => 'https://openrouter.ai/api/v1',
                'auth_method' => 'bearer',
                'key_source' => 'Create a key at openrouter.ai → Keys.',
                'note' => 'An aggregator: one key reaches many companies\' models, and it prices them with its own margin on top. Useful for breadth, worse for cost visibility than going direct.',
            ],
            'huggingface' => [
                'name' => 'Hugging Face',
                'adapter_type' => OpenAiCompatibleAdapter::KEY,
                'api_base_url' => 'https://router.huggingface.co/v1',
                'auth_method' => 'bearer',
                'key_source' => 'Create a token at huggingface.co → Settings → Access Tokens.',
                'note' => 'An aggregator over many hosted models. Availability varies by model and can change without notice, so check the catalog after a sync.',
            ],
        ];
    }

    /**
     * One preset, or null.
     *
     * Null rather than an exception: a preset key can arrive from a form, and
     * an unknown one means "start from blank", not "fail".
     *
     * @return array<string, string>|null
     */
    public function preset(?string $key): ?array
    {
        return $key === null ? null : ($this->presets()[$key] ?? null);
    }

    /** @return array<string, string> the picker an administrator sees */
    public function presetOptions(): array
    {
        return array_map(fn (array $preset) => $preset['name'], $this->presets());
    }
}
