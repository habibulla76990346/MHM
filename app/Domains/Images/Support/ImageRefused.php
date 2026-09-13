<?php

namespace App\Domains\Images\Support;

use RuntimeException;

/**
 * Why a picture could not be asked for, in words the customer can act on.
 *
 * Each of these is a condition the PRODUCT creates — a switch, an allowance, a
 * missing configuration — never a provider failure, which has its own
 * vocabulary in `ErrorClass` and never reaches a customer as raw text.
 */
class ImageRefused extends RuntimeException
{
    public static function disabled(): self
    {
        return new self(__('Image generation is switched off.'));
    }

    public static function empty(): self
    {
        return new self(__('Describe the picture you want.'));
    }

    public static function badSize(): self
    {
        return new self(__('That is not a size Aziv AI can produce.'));
    }

    public static function noModel(): self
    {
        return new self(__('No image model is available. An administrator needs to add a provider that generates images and enable one of its models.'));
    }

    public static function outOfCredits(): self
    {
        return new self(__('You do not have enough credits for that. Top up or wait for your plan to renew.'));
    }

    public static function dailyLimit(int $limit): self
    {
        return new self(__('You have reached the limit of :limit images a day.', ['limit' => $limit]));
    }

    public static function planLimit(int $limit): self
    {
        return new self(__('Your plan includes :limit images per billing period, and you have used them all.', ['limit' => $limit]));
    }

    public static function notYours(): self
    {
        return new self(__('That image is not yours.'));
    }
}
