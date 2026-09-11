<?php

namespace App\Domains\Chat\Services;

use App\Domains\Chat\Models\Conversation;
use App\Domains\Chat\Models\Message;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The conversation list: create, rename, archive, delete, search (§15).
 */
class ConversationService
{
    public function start(User $user, ?int $personaId = null, ?int $modelId = null): Conversation
    {
        return Conversation::create([
            'user_id' => $user->getKey(),
            'persona_id' => $personaId,
            'pinned_model_id' => $modelId,
            'routing_mode' => $modelId ? Conversation::ROUTING_SPECIFIC_MODEL : Conversation::ROUTING_AUTO,
            'last_message_at' => now(),
        ]);
    }

    /**
     * The chat list, newest activity first.
     *
     * @return Collection<int, Conversation>
     */
    public function list(User $user, bool $archived = false, string $search = ''): Collection
    {
        return Conversation::forUser($user)
            ->where('is_archived', $archived)
            ->when($search !== '', fn ($q) => $this->applySearch($q, $search))
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get();
    }

    /**
     * Search titles AND message content.
     *
     * Titles alone would be close to useless: a conversation is named from its
     * first sentence, and people search for something they remember saying
     * halfway through.
     */
    private function applySearch($query, string $search)
    {
        $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';

        return $query->where(function ($q) use ($term) {
            $q->where('title', 'like', $term)
                ->orWhereExists(function ($sub) use ($term) {
                    $sub->select(DB::raw(1))
                        ->from('chat_messages')
                        ->whereColumn('chat_messages.conversation_id', 'chat_conversations.id')
                        ->where('chat_messages.content', 'like', $term);
                });
        });
    }

    public function rename(Conversation $conversation, string $title): void
    {
        $title = trim(strip_tags($title));

        $conversation->forceFill([
            'title' => $title === '' ? $conversation->titleFrom($this->firstUserMessage($conversation)) : mb_substr($title, 0, 120),
        ])->save();
    }

    public function archive(Conversation $conversation, bool $archived = true): void
    {
        $conversation->forceFill(['is_archived' => $archived])->save();
    }

    /**
     * Soft delete. The customer sees it gone; retention decides when the rows
     * actually go, so a mis-click is recoverable and a genuine deletion still
     * happens on schedule.
     */
    public function delete(Conversation $conversation): void
    {
        $conversation->delete();
    }

    private function firstUserMessage(Conversation $conversation): string
    {
        return (string) $conversation->messages()
            ->where('role', Message::ROLE_USER)
            ->value('content');
    }
}
