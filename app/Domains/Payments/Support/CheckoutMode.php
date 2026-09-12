<?php

namespace App\Domains\Payments\Support;

/**
 * How a gateway presents its payment step (Addendum D §2).
 *
 * The five named gateways do not agree on this: some open a modal over the
 * page, some send the browser away, some post server to server. The adapter
 * DECLARES its mode and one checkout wrapper handles all of them, so the
 * customer's experience does not change when the owner switches gateway —
 * which is exactly what was asked for.
 */
class CheckoutMode
{
    /** The gateway's JavaScript opens a modal over Aziv AI's own page. */
    public const SDK_MODAL = 'sdk_modal';

    /** The browser leaves for the gateway and comes back. */
    public const REDIRECT = 'redirect';

    /** A gateway-hosted payment page. Behaves like a redirect to us. */
    public const HOSTED = 'hosted';

    /** Server to server; Aziv AI collects nothing sensitive itself. */
    public const S2S = 's2s';

    /** @return array<string, string> */
    public static function all(): array
    {
        return [
            self::SDK_MODAL => 'Opens over your page',
            self::REDIRECT => 'Sends the customer to the gateway',
            self::HOSTED => 'Gateway-hosted page',
            self::S2S => 'Server to server',
        ];
    }

    /** Whether the customer's browser leaves Aziv AI. */
    public static function leavesSite(string $mode): bool
    {
        return in_array($mode, [self::REDIRECT, self::HOSTED], true);
    }
}
