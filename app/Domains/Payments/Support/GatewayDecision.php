<?php

namespace App\Domains\Payments\Support;

use App\Domains\Payments\Models\PaymentGatewayRecord;

/**
 * Which gateway was chosen, and why every other one was not.
 *
 * The rejections are kept for the same reason the AI router keeps its
 * candidates: "no gateway available" is three different problems — nothing
 * configured, nothing that takes this currency, nothing that can renew a
 * subscription — with three different remedies, and an owner told only the
 * first will look in the wrong place.
 */
final class GatewayDecision
{
    /**
     * @param  array<int, array{gateway: string, reason: string, stage: int}>  $rejected
     * @param  array<int, string>  $required
     */
    public function __construct(
        public readonly ?PaymentGatewayRecord $gateway,
        public readonly array $required = [],
        public readonly array $rejected = [],
    ) {}

    public function chosen(): bool
    {
        return $this->gateway !== null;
    }

    /**
     * Why nothing could take this payment, in words an owner can act on.
     *
     * THE FURTHEST-THROUGH REASON WINS, not the most common one. A platform
     * with four gateways switched off and one that got as far as "it cannot
     * renew a subscription" has one interesting problem and four irrelevant
     * ones — and telling the owner "they are switched off" sends them to the
     * wrong screen.
     */
    public function explainFailure(): string
    {
        if ($this->rejected === []) {
            return __('No payment gateway is set up yet.');
        }

        $furthest = null;

        foreach ($this->rejected as $rejection) {
            if ($furthest === null || ($rejection['stage'] ?? 0) > ($furthest['stage'] ?? 0)) {
                $furthest = $rejection;
            }
        }

        return (string) ($furthest['reason'] ?? __('No payment gateway is available.'));
    }
}
