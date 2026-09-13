<?php

namespace App\Domains\Images\Policies;

use App\Domains\Images\Models\ImageGeneration;
use App\Models\User;

/**
 * A customer's pictures are their content.
 *
 * ADMINISTRATORS ARE NOT AUTOMATICALLY VIEWERS. `media.view` shows an
 * administrator that a generation happened, what it cost and whether it
 * failed — the operational facts. It does not open the picture, exactly as
 * `knowledge.view` does not open a personal collection: seeing that a customer
 * generated forty images is operations, and looking at them is not.
 *
 * `media.delete_any` exists for the one case that genuinely needs it — a
 * report about the content of a specific image — and is granted to nobody but
 * a full administrator.
 */
class ImageGenerationPolicy
{
    public function view(User $user, ImageGeneration $generation): bool
    {
        return $generation->user_id === $user->getKey();
    }

    public function delete(User $user, ImageGeneration $generation): bool
    {
        return $generation->user_id === $user->getKey() || $user->can('media.delete_any');
    }

    public function regenerate(User $user, ImageGeneration $generation): bool
    {
        return $generation->user_id === $user->getKey();
    }
}
