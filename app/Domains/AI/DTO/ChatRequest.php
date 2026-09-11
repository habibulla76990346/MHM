<?php

namespace App\Domains\AI\DTO;

/**
 * A request in Aziv AI's own format (blueprint §12).
 *
 * Carries the model IDENTIFIER, not a model name chosen in code — the router
 * picked it from the catalog by capability, which is what Rule 5 requires.
 */
final class ChatRequest
{
    /**
     * @param  array<int, ChatMessage>  $messages
     * @param  array<string, mixed>  $options  provider-neutral extras
     */
    public function __construct(
        public readonly string $modelIdentifier,
        public readonly array $messages,
        public readonly ?float $temperature = null,
        public readonly ?int $maxTokens = null,
        public readonly bool $stream = false,
        public readonly array $options = [],
    ) {}

    public function withMaxTokens(int $maxTokens): self
    {
        return new self(
            $this->modelIdentifier,
            $this->messages,
            $this->temperature,
            $maxTokens,
            $this->stream,
            $this->options,
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function messagesArray(): array
    {
        return array_map(fn (ChatMessage $m) => $m->toArray(), $this->messages);
    }

    /** The system prompt, for providers that take it separately. */
    public function systemPrompt(): ?string
    {
        foreach ($this->messages as $message) {
            if ($message->role === ChatMessage::ROLE_SYSTEM) {
                return $message->content;
            }
        }

        return null;
    }

    /** @return array<int, ChatMessage> everything except the system prompt */
    public function conversation(): array
    {
        return array_values(array_filter(
            $this->messages,
            fn (ChatMessage $m) => $m->role !== ChatMessage::ROLE_SYSTEM,
        ));
    }
}
