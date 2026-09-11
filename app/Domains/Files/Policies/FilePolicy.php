<?php

namespace App\Domains\Files\Policies;

use App\Domains\Files\Models\File;
use App\Models\User;

/**
 * US-9: a valid URL is not authorisation.
 *
 * Every download runs this policy. Files are addressed by UUID rather than a
 * sequential id, but that is obscurity — this is the control.
 */
class FilePolicy
{
    public function view(User $user, File $file): bool
    {
        if ($file->isQuarantined()) {
            // Not even the owner may retrieve a quarantined file.
            return false;
        }

        if ($file->user_id === $user->getKey()) {
            return true;
        }

        // Reserved for investigating a support case; Super Admin only.
        return $user->can('files.download_any');
    }

    public function delete(User $user, File $file): bool
    {
        return $file->user_id === $user->getKey() || $user->can('files.delete');
    }
}
