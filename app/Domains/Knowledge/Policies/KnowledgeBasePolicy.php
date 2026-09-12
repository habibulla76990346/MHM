<?php

namespace App\Domains\Knowledge\Policies;

use App\Domains\Knowledge\Models\KnowledgeBase;
use App\Models\User;

/**
 * §17: "sensitive file access must follow role-based permissions and privacy
 * rules."
 *
 * The policy DELEGATES to the model rather than restating the rule. One
 * implementation of "who may read this" means a gate check and a retrieval
 * check can never disagree — and disagreeing is how a customer's documents
 * end up in somebody else's answer.
 */
class KnowledgeBasePolicy
{
    public function view(User $user, KnowledgeBase $base): bool
    {
        return $base->isReadableBy($user);
    }

    public function update(User $user, KnowledgeBase $base): bool
    {
        return $base->isWritableBy($user);
    }

    public function delete(User $user, KnowledgeBase $base): bool
    {
        return $base->isWritableBy($user);
    }

    /** Adding documents is the same authority as changing the base. */
    public function addDocuments(User $user, KnowledgeBase $base): bool
    {
        return $base->isWritableBy($user);
    }
}
