<?php

namespace App\Domains\Chat\Policies;

use App\Domains\Chat\Models\Conversation;
use App\Models\User;

/**
 * A conversation belongs to one customer.
 *
 * Deny-by-default, as everywhere else: ownership is the only thing that grants
 * access, and an administrator does NOT get to read customer conversations
 * through this policy. Reading someone's chats is a support action with its
 * own permission and its own audit trail, not a side effect of being an admin.
 */
class ConversationPolicy
{
    public function view(User $user, Conversation $conversation): bool
    {
        return $conversation->user_id === $user->getKey();
    }

    public function update(User $user, Conversation $conversation): bool
    {
        return $conversation->user_id === $user->getKey();
    }

    public function delete(User $user, Conversation $conversation): bool
    {
        return $conversation->user_id === $user->getKey();
    }
}
