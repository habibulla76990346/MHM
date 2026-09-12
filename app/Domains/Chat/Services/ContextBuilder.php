<?php

namespace App\Domains\Chat\Services;

use App\Domains\AI\DTO\ChatMessage;
use App\Domains\AI\Models\AiModel;
use App\Domains\Chat\Models\Conversation;
use App\Domains\Chat\Models\Message;
use App\Domains\Chat\Models\Persona;
use App\Domains\Knowledge\Services\RetrievalService;
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
    public function __construct(private readonly RetrievalService $retrieval) {}

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

        /**
         * Retrieved passages, if this conversation has documents attached.
         *
         * PLACED AS A SYSTEM MESSAGE, before the history and after the
         * persona. As a user message it would look like something the customer
         * typed and the model would answer it; as part of the persona it would
         * persist into turns it has nothing to do with.
         *
         * IT SPENDS THE SAME BUDGET as the conversation, and is counted first.
         * Retrieval that quietly pushed the customer's last three messages out
         * of context would answer a question from documents while forgetting
         * what was being discussed.
         */
        $retrieved = $this->retrievedContext($conversation, $upTo, $budget);

        if ($retrieved !== null) {
            $messages[] = ChatMessage::system($retrieved);
            $budget -= $this->estimateTokens($retrieved);
        }

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
     * The document passages that bear on the newest question.
     *
     * Returns null when there is nothing attached, nothing relevant, or
     * retrieval failed — all three of which are ordinary and none of which
     * should stop a conversation. `RetrievalService` decides what may be
     * searched; this only decides how much of it fits and how it reads.
     *
     * EVERY PASSAGE IS CITED. A model given unattributed text presents it as
     * its own knowledge, and a customer cannot tell which half of an answer
     * came from their document and which from the model's training. The
     * citation is also what makes a wrong answer checkable.
     */
    private function retrievedContext(Conversation $conversation, ?Message $upTo, int $budget): ?string
    {
        if (! settings('knowledge.enabled')) {
            return null;
        }

        $question = $this->newestQuestion($conversation, $upTo);

        if ($question === null) {
            return null;
        }

        $chunks = $this->retrieval->retrieve($conversation, $question, $upTo);

        if ($chunks === []) {
            return null;
        }

        // Whichever ceiling is lower: the owner's setting, or a third of this
        // model's budget. A small model must not have its whole context filled
        // with documents.
        $ceiling = min(
            (int) settings('knowledge.max_context_tokens'),
            (int) floor($budget / 3),
        );

        $lines = [];
        $used = 0;

        foreach ($chunks as $chunk) {
            $passage = '['.$chunk->citation().'] '.$chunk->content;
            $cost = $this->estimateTokens($passage);

            if ($lines !== [] && $used + $cost > $ceiling) {
                break;
            }

            $lines[] = $passage;
            $used += $cost;
        }

        if ($lines === []) {
            return null;
        }

        return implode("\n\n", array_merge([
            __('The following passages come from documents the customer has provided. Use them when they are relevant, cite the source in square brackets when you do, and say plainly when they do not contain the answer rather than inventing one.'),
        ], $lines));
    }

    /**
     * What the customer most recently asked.
     *
     * Retrieval is driven by the question, not by the whole conversation: a
     * search over ten turns of small talk retrieves whatever the small talk
     * resembles.
     */
    private function newestQuestion(Conversation $conversation, ?Message $upTo): ?string
    {
        $question = $this->history($conversation, $upTo)
            ->reverse()
            ->firstWhere('role', Message::ROLE_USER)?->content;

        return filled($question) ? (string) $question : null;
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
