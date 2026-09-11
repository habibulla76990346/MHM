<?php

namespace App\Domains\Chat\Policies;

use App\Domains\Chat\Models\Message;
use App\Models\User;

class MessagePolicy
{
    /**
     * Streaming, stopping and completing all touch one in-flight reply.
     *
     * NOTE: the streaming endpoints do NOT rely on this. Spatie's own
     * `Gate::before` grants a Super Admin every ability, so a policy alone
     * would let an administrator read a customer's conversation. Those routes
     * compare ownership directly instead; this stays as the statement of
     * intent for anything that goes through the Gate.
     */
    public function stream(User $user, Message $message): bool
    {
        return $message->conversation?->user_id === $user->getKey();
    }

    public function update(User $user, Message $message): bool
    {
        return $message->conversation?->user_id === $user->getKey();
    }
}
