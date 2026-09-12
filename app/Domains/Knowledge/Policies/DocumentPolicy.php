<?php

namespace App\Domains\Knowledge\Policies;

use App\Domains\Knowledge\Models\Document;
use App\Models\User;

/** A document is as private as the base holding it. */
class DocumentPolicy
{
    public function view(User $user, Document $document): bool
    {
        return $document->knowledgeBase?->isReadableBy($user) ?? false;
    }

    public function delete(User $user, Document $document): bool
    {
        return $document->knowledgeBase?->isWritableBy($user) ?? false;
    }
}
