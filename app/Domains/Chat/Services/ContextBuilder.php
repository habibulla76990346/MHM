<?php

namespace App\Domains\Chat\Services;

use App\Domains\AI\DTO\ChatMessage;
use App\Domains\AI\Models\AiModel;
use App\Domains\Chat\Models\Conversation;
use App\Domains\Chat\Models\Message;
use App\Domains\Chat\Models\Persona;
use Illuminate\Support\Collection;

/**
 * Decides how much of a conversation the AI actually sees (§15).
 *
 * This is the single biggest lever on what a chat COSTS. Every message resends
 * the history, so an unbounded context means a conversation gets more
 * expensive with every turn until it hits the model's ceiling and fails.
 *
 * Three limits, applied in order, because each catches something the others
 * miss:
 *
 *  1. A MESSAGE COUNT, so an old conversation does not carry a hundred turns.
 *  2. A TOKEN BUDGET, because twenty short messages and twenty pasted
 *     documents are not the same amount of money.
 *  3. THE MODEL'S OWN CONTEXT WINDOW, which no setting may exceed — a request
 *     that overflows it is rejected by the provider after being paid for.
 */
class ContextBuilder
{
    /**
     * Roughly four characters per token across English prose and code.
     *
     * Deliberately an estimate. A real tokeniser would be per-provider, would
     * need updating whenever any of them changed, and is the wrong trade for a
     * safety limit — the budget is a ceiling, not an invoice.
     */
    private const CHARS_PER_TOKEN = 4;

    /**
     * @return array<int, ChatMessage> ready for a ChatRequest, oldest first
     */
    public function build(Conversation $conversation, AiModel $model, ?Message $upTo = null): array
    {
        $messages = [];

        if ($prompt = $this->systemPrompt($conversation)) {
            $messages[] = ChatMessage::system($prompt);
        }

        $history = $this->history($conversation, $upTo);
        $budget = $this->tokenBudget($model);

        // Walk backwards from the newest, so the messages that survive a tight
        // budget are the ones that matter most to the reply.
        $selected = [];
        $used = $prompt ? $this->estimateTokens($prompt) : 0;

        foreach ($history->reverse() as $message) {
            $cost = $this->estimateTokens((string) $message->content);

            // The newest message always goes, even if it alone exceeds the
            // budget — dropping what the customer just typed would answer a
            // question nobody asked. The provider's own limit is what refuses
            // it, with a message that says so.
            if ($selected !== [] && $used + $cost > $budget) {
                break;
            }

            $used += $cost;
            $selected[] = $message;
        }

        foreach (array_reverse($selected) as $message) {
            $messages[] = $message->role === Message::ROLE_ASSISTANT
                ? ChatMessage::assistant((string) $message->content)
                : ChatMessage::user((string) $message->content, $message->attachmentPayload());
        }

        return $messages;
    }

    /**
     * The system prompt for this conversation: its persona, or the default
     * one an administrator set. Never anything a customer typed.
     */
    public function systemPrompt(Conversation $conversation): ?string
    {
        $persona = $conversation->persona ?? Persona::default();

        return $persona?->system_prompt ?: null;
    }

    /**
     * The history to consider, ending at a given message.
     *
     * `$upTo` is what makes regeneration correct: regenerating an answer must
     * send the conversation AS IT WAS before that answer, not including it.
     *
     * @return Collection<int, Message>
     */
    public function history(Conversation $conversation, ?Message $upTo = null): Collection
    {
        $limit = max(2, (int) settings('chat.context_message_limit'));

        return $conversation->visibleMessages()
            ->whereIn('status', [Message::STATUS_COMPLETE, Message::STATUS_STOPPED])
            ->when($upTo, fn ($q) => $q->where('id', '<', $upTo->getKey()))
            ->whereNotNull('content')
            ->where('content', '!=', '')
            ->get()
            ->slice(-$limit)
            ->values();
    }

    /**
     * The effective budget: the configured one, or the model's window, or
     * whichever is smaller.
     *
     * Reserving room for the reply matters — a context that exactly fills the
     * window leaves the model nowhere to answer.
     */
    public function tokenBudget(AiModel $model): int
    {
        $configured = max(500, (int) settings('chat.context_token_budget'));
        $reply = max(64, (int) settings('chat.max_output_tokens'));

        if (! $model->context_window) {
            return $configured;
        }

        $available = max(500, $model->context_window - $reply);

        return min($configured, $available);
    }

    public function estimateTokens(string $text): int
    {
        return (int) ceil(mb_strlen($text) / self::CHARS_PER_TOKEN);
    }
}
