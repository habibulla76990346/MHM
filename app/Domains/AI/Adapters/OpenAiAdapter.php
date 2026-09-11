<?php

namespace App\Domains\AI\Adapters;

use App\Domains\AI\Contracts\SupportsVision;
use App\Domains\AI\DTO\DiscoveredModel;
use App\Domains\AI\Support\Capability;

/**
 * OpenAI (blueprint §12).
 *
 * OpenAI defined the shape that `OpenAiCompatibleAdapter` implements, so this
 * inherits nearly everything. What it adds is the knowledge that only a
 * first-party adapter can have: which of OpenAI's own models are chat models
 * rather than embeddings, moderation or audio endpoints.
 *
 * That filtering is NOT a hard-coded model list — Rule 5 stands. The catalog
 * still comes from OpenAI's /models endpoint; this only declines to import
 * identifiers that are demonstrably not chat models, so an owner's catalog is
 * not filled with entries that would fail the moment anyone selected one.
 */
class OpenAiAdapter extends OpenAiCompatibleAdapter implements SupportsVision
{
    public const KEY = 'openai';

    public const DEFAULT_BASE_URL = 'https://api.openai.com/v1';

    /**
     * Endpoint families that exist on /models but are not chat completions.
     *
     * Matched as prefixes of the identifier the provider itself returns —
     * never an allowlist of model names, which would go stale the week after
     * it was written.
     */
    private const NON_CHAT_PREFIXES = [
        'text-embedding', 'whisper', 'tts-', 'dall-e', 'omni-moderation',
        'text-moderation', 'babbage', 'davinci', 'sora',
    ];

    public function capabilities(): array
    {
        return [
            Capability::CHAT,
            Capability::STREAMING,
            Capability::VISION,
            Capability::TOOL_USE,
            Capability::JSON_MODE,
            Capability::LONG_CONTEXT,
        ];
    }

    /** @return array<int, DiscoveredModel> */
    public function listModels(): array
    {
        $models = [];

        foreach (parent::listModels() as $model) {
            if ($this->isNonChatEndpoint($model->identifier)) {
                continue;
            }

            $models[] = new DiscoveredModel(
                identifier: $model->identifier,
                displayName: $model->displayName,
                description: $model->description,
                contextWindow: $model->contextWindow,
                maxOutputTokens: $model->maxOutputTokens,
                // Chat and streaming only. Whether a given model can see
                // images is a fact about that model, and OpenAI's /models
                // response does not state it — so an administrator ticks it,
                // rather than a regex guessing from the name.
                capabilities: [Capability::CHAT, Capability::STREAMING],
            );
        }

        return $models;
    }

    private function isNonChatEndpoint(string $identifier): bool
    {
        foreach (self::NON_CHAT_PREFIXES as $prefix) {
            if (str_starts_with($identifier, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
