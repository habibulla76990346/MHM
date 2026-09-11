<?php

namespace App\Domains\Chat\Support;

use RuntimeException;

/**
 * Why a turn could not be taken, in words the customer can act on.
 *
 * Every one of these is a condition the product creates — a limit, a missing
 * configuration — rather than a provider failure, which has its own
 * vocabulary in ErrorClass.
 */
class ChatRefused extends RuntimeException
{
    public static function noModel(): self
    {
        return new self(__('No AI model is available right now. An administrator needs to enable one.'));
    }

    public static function pinnedModelUnavailable(): self
    {
        return new self(__('The model this conversation is pinned to is not available. Choose another model.'));
    }

    public static function needsVision(): self
    {
        return new self(__('No available model can read images. Remove the attachment, or ask an administrator to enable a model that supports images.'));
    }

    public static function tooLong(int $limit): self
    {
        return new self(__('That message is too long. The limit is :limit characters.', ['limit' => number_format($limit)]));
    }

    public static function empty(): self
    {
        return new self(__('Type a message first.'));
    }

    public static function tooManyAttachments(int $limit): self
    {
        return new self(__('You can attach at most :limit file(s) to a message.', ['limit' => $limit]));
    }

    public static function rateLimited(int $seconds): self
    {
        return new self(__('You are sending messages very quickly. Try again in :seconds seconds.', ['seconds' => max(1, $seconds)]));
    }

    public static function alreadyAnswering(): self
    {
        return new self(__('Wait for the current reply to finish first.'));
    }
}
