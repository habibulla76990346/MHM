<?php

namespace App\Domains\AI\Support;

/**
 * What a model can DO. The application asks for these, never for brand names
 * (Rule 5).
 *
 * This is the vocabulary the router speaks: "I need vision and a 100k context"
 * rather than "use gpt-4o". Adding a provider changes rows, not code, because
 * nothing above this layer knows who answered.
 */
class Capability
{
    public const CHAT = 'chat';

    public const STREAMING = 'streaming';

    public const VISION = 'vision';

    public const IMAGE_GENERATION = 'image_generation';

    public const EMBEDDINGS = 'embeddings';

    public const TRANSCRIPTION = 'transcription';

    public const SPEECH = 'speech';

    public const TOOL_USE = 'tool_use';

    public const JSON_MODE = 'json_mode';

    public const LONG_CONTEXT = 'long_context';

    /** @return array<string, array{label: string, description: string}> */
    public static function all(): array
    {
        return [
            self::CHAT => ['label' => 'Chat', 'description' => 'Ordinary text conversation.'],
            self::STREAMING => ['label' => 'Streaming', 'description' => 'Replies arrive word by word rather than all at once.'],
            self::VISION => ['label' => 'Reads images', 'description' => 'The customer can attach a picture and ask about it.'],
            self::IMAGE_GENERATION => ['label' => 'Makes images', 'description' => 'Generates pictures from a description.'],
            self::EMBEDDINGS => ['label' => 'Embeddings', 'description' => 'Turns text into numbers for search and comparison.'],
            self::TRANSCRIPTION => ['label' => 'Speech to text', 'description' => 'Turns an audio recording into text.'],
            self::SPEECH => ['label' => 'Text to speech', 'description' => 'Reads text aloud.'],
            self::TOOL_USE => ['label' => 'Tools', 'description' => 'The model can call functions Aziv AI provides.'],
            self::JSON_MODE => ['label' => 'Structured output', 'description' => 'Guarantees valid JSON back.'],
            self::LONG_CONTEXT => ['label' => 'Long context', 'description' => 'Handles very large documents in one go.'],
        ];
    }

    /** @return array<int, string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function exists(string $capability): bool
    {
        return isset(self::all()[$capability]);
    }

    public static function label(string $capability): string
    {
        return self::all()[$capability]['label'] ?? $capability;
    }
}
