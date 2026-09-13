<?php

namespace App\Domains\Voice\Support;

use RuntimeException;

/**
 * Why a recording or a reading could not be asked for, in words the customer
 * can act on. Never a provider's own text.
 */
class VoiceRefused extends RuntimeException
{
    public static function inputDisabled(): self
    {
        return new self(__('Speaking to Aziv AI is switched off.'));
    }

    public static function outputDisabled(): self
    {
        return new self(__('Reading replies aloud is switched off.'));
    }

    public static function noModel(): self
    {
        return new self(__('No voice model is available. An administrator needs to add a provider that handles audio and enable one of its models.'));
    }

    public static function empty(): self
    {
        return new self(__('There was nothing to send.'));
    }

    public static function tooLong(int $seconds): self
    {
        return new self(__('Recordings can be up to :seconds seconds.', ['seconds' => $seconds]));
    }

    public static function outOfCredits(): self
    {
        return new self(__('You do not have enough credits for that. Top up or wait for your plan to renew.'));
    }

    public static function dailyLimit(int $minutes): self
    {
        return new self(__('You have used your :minutes minutes of audio for today.', ['minutes' => $minutes]));
    }

    public static function planLimit(int $minutes): self
    {
        return new self(__('Your plan includes :minutes minutes of audio per billing period, and you have used them all.', ['minutes' => $minutes]));
    }

    public static function notYours(): self
    {
        return new self(__('That is not yours.'));
    }
}
