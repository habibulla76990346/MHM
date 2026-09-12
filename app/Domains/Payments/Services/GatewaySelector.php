<?php

namespace App\Domains\Payments\Services;

use App\Domains\Payments\Models\PaymentGatewayRecord;
use App\Domains\Payments\Models\PaymentGatewayRule;
use App\Domains\Payments\Support\Capability;
use App\Domains\Payments\Support\GatewayDecision;

/**
 * Choosing a gateway, capability first (Addendum D §3).
 *
 * THE GUARANTEE THIS EXISTS FOR: *a subscription is never routed to a gateway
 * that cannot renew it.* Not every processor does recurring billing to the
 * same degree, and one that cannot will either fail at checkout or — far worse
 * — quietly take a single payment that never charges again. Nobody notices
 * until the month the money does not arrive.
 *
 * So capability is a HARD FILTER applied before an owner's preferences are
 * even read: a routing rule can order the candidates, it can never widen them.
 * When nothing qualifies this fails loudly, with the reason, rather than
 * downgrading the sale.
 */
class GatewaySelector
{
    public function __construct(private readonly PaymentGatewayRegistry $registry) {}

    public function select(
        string $paymentType,
        string $currency,
        ?string $country = null,
        ?int $planId = null,
        string $renewalMechanism = 'manual',
    ): GatewayDecision {
        $required = Capability::requiredFor($paymentType, $renewalMechanism);
        $rejected = [];
        $eligible = [];

        $gateways = PaymentGatewayRecord::with(['credentials', 'rules'])
            ->orderBy('priority')
            ->get();

        foreach ($gateways as $gateway) {
            $rejection = $this->rejectionFor($gateway, $required, $currency, $country);

            if ($rejection !== null) {
                $rejected[] = ['gateway' => $gateway->name] + $rejection;

                continue;
            }

            $eligible[] = $gateway;
        }

        if ($eligible === []) {
            return new GatewayDecision(null, $required, $rejected);
        }

        return new GatewayDecision(
            $this->preferred($eligible, $paymentType, $country, $currency, $planId),
            $required,
            $rejected,
        );
    }

    /**
     * @param  array<int, string>  $required
     * @return array{reason: string, stage: int}|null why this gateway cannot serve the request
     *
     * The STAGE says how far it got. It is what lets the failure message name
     * the interesting problem rather than the most common one.
     */
    private function rejectionFor(
        PaymentGatewayRecord $gateway,
        array $required,
        string $currency,
        ?string $country,
    ): ?array {
        if ($gateway->status !== PaymentGatewayRecord::STATUS_ACTIVE) {
            return ['reason' => __('It is switched off.'), 'stage' => 1];
        }

        if ($gateway->maintenance_mode) {
            return ['reason' => __('It is in maintenance.'), 'stage' => 2];
        }

        $adapter = $this->registry->for($gateway);

        if (! $adapter) {
            return ['reason' => __('Its adapter is missing.'), 'stage' => 3];
        }

        if (! $gateway->activeCredential()) {
            return [
                'reason' => __('It has no credentials for :mode mode.', ['mode' => $gateway->mode]),
                'stage' => 4,
            ];
        }

        // THE CAPABILITY GUARD. Read from the ADAPTER, not the configuration
        // row: a row can be edited to claim anything, and the claim that
        // matters is the one the code can actually honour.
        foreach ($required as $capability) {
            if (! $adapter->supports($capability)) {
                return [
                    'reason' => __('It cannot handle :capability.', [
                        'capability' => strtolower(Capability::label($capability)),
                    ]),
                    'stage' => 5,
                ];
            }
        }

        if (! $gateway->handles($currency)) {
            return [
                'reason' => __('It is not set up for :currency.', ['currency' => strtoupper($currency)]),
                'stage' => 6,
            ];
        }

        if (! $gateway->servesCountry($country)) {
            return ['reason' => __('It is not set up for that country.'), 'stage' => 7];
        }

        return null;
    }

    /**
     * The owner's own order, among the gateways that can actually do the job.
     *
     * A matching rule wins first, then priority, then the default. Preference
     * only ever ORDERS what capability has already allowed.
     *
     * @param  array<int, PaymentGatewayRecord>  $eligible
     */
    private function preferred(
        array $eligible,
        string $paymentType,
        ?string $country,
        string $currency,
        ?int $planId,
    ): PaymentGatewayRecord {
        $ruled = [];

        foreach ($eligible as $gateway) {
            $rule = $gateway->rules
                ->filter(fn (PaymentGatewayRule $r) => $r->matches($paymentType, $country, $currency, $planId))
                ->sortBy('priority')
                ->first();

            $ruled[] = [
                'gateway' => $gateway,
                // A gateway with no matching rule sorts after every gateway
                // that has one, rather than being excluded: rules express a
                // preference, not a whitelist.
                'rule_priority' => $rule?->priority ?? PHP_INT_MAX,
                'specificity' => $rule ? $this->specificity($rule) : -1,
            ];
        }

        usort($ruled, function (array $a, array $b) {
            // A rule naming this exact plan beats one naming only the type.
            if ($a['specificity'] !== $b['specificity']) {
                return $b['specificity'] <=> $a['specificity'];
            }

            if ($a['rule_priority'] !== $b['rule_priority']) {
                return $a['rule_priority'] <=> $b['rule_priority'];
            }

            if ($a['gateway']->is_default !== $b['gateway']->is_default) {
                return $b['gateway']->is_default <=> $a['gateway']->is_default;
            }

            return $a['gateway']->priority <=> $b['gateway']->priority;
        });

        return $ruled[0]['gateway'];
    }

    /** How specific a rule is — more named fields wins. */
    private function specificity(PaymentGatewayRule $rule): int
    {
        return (int) ($rule->plan_id !== null) * 4
            + (int) ($rule->currency !== null) * 2
            + (int) ($rule->country !== null);
    }
}
