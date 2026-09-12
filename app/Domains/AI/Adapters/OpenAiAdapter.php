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
     * Endpoint families that exist on /models and cannot serve any capability
     * Aziv AI currently routes.
     *
     * Matched as prefixes of the identifier the provider itself returns —
     * never an allowlist of model names, which would go stale the week after
     * it was written. Importing these would fill an owner's catalog with
     * entries that fail the moment anyone selects one.
     */
    private const UNUSABLE_PREFIXES = [
        'omni-moderation', 'text-moderation', 'babbage', 'davinci', 'sora',
    ];

    /**
     * Families that ARE usable, just not for chat.
     *
     * Dropping these was right when chat was the only capability. It stopped
     * being right the moment knowledge bases needed something to embed with:
     * a catalog with no embedding model in it means the router has nothing to
     * choose and an owner has nothing to enable. They are imported and
     * CLASSIFIED instead — which is what the capability system is for.
     */
    private const CAPABILITY_PREFIXES = [
        'text-embedding' => Capability::EMBEDDINGS,
        'whisper' => Capability::TRANSCRIPTION,
        'tts-' => Capability::SPEECH,
        'dall-e' => Capability::IMAGE_GENERATION,
        'gpt-image' => Capability::IMAGE_GENERATION,
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
            if ($this->isUnusable($model->identifier)) {
                continue;
            }

            $models[] = new DiscoveredModel(
                identifier: $model->identifier,
                displayName: $model->displayName,
                description: $model->description,
                contextWindow: $model->contextWindow,
                maxOutputTokens: $model->maxOutputTokens,
                // Whether a given CHAT model can also see images is a fact
                // about that model, and OpenAI's /models response does not
                // state it — so an administrator ticks it, rather than a
                // regex guessing from the name. The single-purpose families
                // below are different: an embeddings endpoint cannot chat,
                // and pretending otherwise would let the router send a
                // conversation somewhere that has no reply to give.
                capabilities: $this->capabilitiesFor($model->identifier),
            );
        }

        return $models;
    }

    private function isUnusable(string $identifier): bool
    {
        foreach (self::UNUSABLE_PREFIXES as $prefix) {
            if (str_starts_with($identifier, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, string> */
    private function capabilitiesFor(string $identifier): array
    {
        foreach (self::CAPABILITY_PREFIXES as $prefix => $capability) {
            if (str_starts_with($identifier, $prefix)) {
                return [$capability];
            }
        }

        return [Capability::CHAT, Capability::STREAMING];
    }
}
