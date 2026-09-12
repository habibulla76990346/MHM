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

    /**
     * Every model that could have answered is temporarily out of rotation —
     * a provider is failing, in maintenance, or over its spending cap.
     *
     * Distinct from noModel() because the remedy is different: this one
     * resolves itself, and telling a customer to fetch an administrator would
     * be wrong.
     */
    public static function temporarilyUnavailable(): self
    {
        return new self(__('The AI service is temporarily unavailable. Please try again in a few minutes.'));
    }

    public static function conversationTooLong(): self
    {
        return new self(__('This conversation has grown too long for the available models. Start a new chat to continue.'));
    }

    public static function tooLong(int $limit): self
    {
        return new self(__('That message is too long. The limit is :limit characters.', ['limit' => number_format($limit)]));
    }

    public static function empty(): self
    {
        return new self(__('Type a message first.'));
    }

    /**
     * Not enough credit for the answer they asked for.
     *
     * Says what to do rather than only what went wrong (Addendum G): a
     * customer who reads "insufficient balance" and nothing else has no idea
     * whether to wait, pay, or complain.
     */
    public static function outOfCredits(): self
    {
        return new self(__('You do not have enough credits left for this. Add credits or upgrade your plan to continue.'));
    }

    /** A plan limit, in the plan's own words. */
    public static function planLimit(string $explanation): self
    {
        return new self($explanation);
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
