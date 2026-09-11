<?php

namespace App\Domains\AI\DTO;

/**
 * One turn of a conversation, in AZIV AI's format.
 *
 * Providers disagree about almost everything here — OpenAI wants
 * role/content, Gemini wants contents/parts, Anthropic lifts the system prompt
 * out of the list entirely. This is the shape the application uses; each
 * adapter translates on the way out.
 */
final class ChatMessage
{
    public const ROLE_SYSTEM = 'system';

    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    /**
     * @param  array<int, array{type: string, url?: string, data?: string, mime?: string}>  $attachments
     */
    public function __construct(
        public readonly string $role,
        public readonly string $content,
        public readonly array $attachments = [],
    ) {}

    public static function system(string $content): self
    {
        return new self(self::ROLE_SYSTEM, $content);
    }

    public static function user(string $content, array $attachments = []): self
    {
        return new self(self::ROLE_USER, $content, $attachments);
    }

    public static function assistant(string $content): self
    {
        return new self(self::ROLE_ASSISTANT, $content);
    }

    public function toArray(): array
    {
        return array_filter([
            'role' => $this->role,
            'content' => $this->content,
            'attachments' => $this->attachments ?: null,
        ], fn ($v) => $v !== null);
    }
}
