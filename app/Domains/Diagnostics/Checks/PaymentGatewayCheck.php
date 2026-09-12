<?php

namespace App\Domains\Diagnostics\Checks;

use App\Domains\Diagnostics\Support\Category;
use App\Domains\Diagnostics\Support\CheckResult;
use App\Domains\Diagnostics\Support\Responsibility;
use App\Domains\Diagnostics\Support\Severity;
use App\Domains\Diagnostics\Support\Status;
use App\Domains\Payments\Models\PaymentGatewayRecord;
use App\Domains\Payments\Models\PaymentWebhookEvent;
use App\Domains\Payments\Services\PaymentGatewayRegistry;

/**
 * Whether the platform can actually take money (Owner Addendum D and G).
 *
 * WHAT THIS CATCHES, in the order it hurts:
 *
 *  - A gateway switched on in LIVE mode with sandbox-looking credentials, or
 *    the reverse. One takes real money in a test; the other fails to take real
 *    money in production, and both are found by a customer rather than an
 *    owner.
 *  - A webhook secret that is missing. Without it every notification is
 *    rejected, and every payment then waits for the sweep — which works, but
 *    slowly, and nobody knows why.
 *  - A run of REJECTED signatures, which is either a wrong secret or somebody
 *    probing the endpoint.
 *
 * Deliberately does NOT call the gateway: connectivity is tested from the
 * gateway screen, on purpose, by someone who meant to.
 */
class PaymentGatewayCheck extends BaseCheck
{
    public function __construct(private readonly PaymentGatewayRegistry $registry) {}

    public function key(): string
    {
        return 'payments.gateways';
    }

    public function title(): string
    {
        return 'Payment gateways';
    }

    public function category(): Category
    {
        return Category::Payments;
    }

    public function isApplicable(): bool
    {
        try {
            return PaymentGatewayRecord::query()->exists();
        } catch (\Throwable) {
            // Before the migration has run this is not applicable rather than
            // broken, and must not take the System Health page down.
            return false;
        }
    }

    public function run(): CheckResult
    {
        $gateways = PaymentGatewayRecord::with('credentials')->get();
        $active = $gateways->where('status', PaymentGatewayRecord::STATUS_ACTIVE);

        if ($active->isEmpty()) {
            return $this->result(
                Status::Grey,
                Severity::Informational,
                Responsibility::Configuration,
                'No payment gateway is switched on.',
                'Nobody can buy a plan until one is configured and enabled.',
                'Open Admin → Payment gateways, enter your credentials for the mode you want, then switch the gateway on.',
            );
        }

        $problems = [];
        $warnings = [];

        foreach ($active as $gateway) {
            $credential = $gateway->activeCredential();

            if (! $credential) {
                $problems[] = $gateway->name.': switched on but has no credentials for '.$gateway->mode.' mode';

                continue;
            }

            if (! $this->registry->for($gateway)) {
                $problems[] = $gateway->name.': no adapter is installed for it';

                continue;
            }

            if (blank($credential->signingSecret())) {
                $problems[] = $gateway->name.': no webhook secret, so every notification it sends will be rejected';
            }

            // A key that names its own mode is the usual shape, and a mismatch
            // is worth saying out loud even though it cannot be proven.
            $mismatch = $this->modeMismatch($gateway, $credential->secret());

            if ($mismatch) {
                $problems[] = $gateway->name.': '.$mismatch;
            }

            $rejections = PaymentWebhookEvent::where('gateway_id', $gateway->getKey())
                ->where('signature_valid', false)
                ->where('received_at', '>=', now()->subDay())
                ->count();

            if ($rejections > 0) {
                $warnings[] = $gateway->name.': '.$rejections.' webhook(s) rejected in the last day';
            }

            if ($gateway->isLive() && ! $credential->isVerified()) {
                $warnings[] = $gateway->name.': live credentials have never been tested';
            }
        }

        if ($problems !== []) {
            return $this->result(
                Status::Red,
                Severity::Critical,
                Responsibility::Configuration,
                implode(' · ', $problems),
                'Payments will fail or will not be credited automatically.',
                'Open Admin → Payment gateways and fix each item above, then use Test connection.',
            );
        }

        if ($warnings !== []) {
            return $this->result(
                Status::Yellow,
                Severity::Medium,
                Responsibility::Configuration,
                implode(' · ', $warnings),
                'Payments are working, but something needs attention.',
                'Open Admin → Payment gateways and check the webhook secret and recent deliveries.',
            );
        }

        return $this->result(
            Status::Green,
            Severity::Informational,
            Responsibility::Configuration,
            $active->count().' gateway(s) configured, each with credentials and a webhook secret.',
            'The platform can take payments.',
            'Nothing to do.',
        );
    }

    /**
     * A key that plainly belongs to the other mode.
     *
     * Only reads the PUBLISHABLE identifier and the key id — never the secret
     * — and only looks for the conventional marker. It cannot prove a
     * mismatch, so it reports what it sees rather than asserting.
     *
     * @param  array<string, string>  $secret
     */
    private function modeMismatch(PaymentGatewayRecord $gateway, array $secret): ?string
    {
        $identifier = strtolower((string) ($gateway->activeCredential()?->publishable_key ?: ($secret['key_id'] ?? '')));

        if ($identifier === '') {
            return null;
        }

        $looksTest = str_contains($identifier, 'test');

        if ($gateway->isLive() && $looksTest) {
            return 'it is in LIVE mode but its key looks like a test key — real payments will fail';
        }

        if (! $gateway->isLive() && ! $looksTest && str_contains($identifier, 'live')) {
            return 'it is in SANDBOX mode but its key looks like a live key — a test could take real money';
        }

        return null;
    }

    private function result(
        Status $status,
        Severity $severity,
        Responsibility $responsibility,
        string $technicalReason,
        string $recommendedAction,
        string $adminAction,
    ): CheckResult {
        return new CheckResult(
            key: $this->key(),
            title: $this->title(),
            category: $this->category(),
            status: $status,
            severity: $severity,
            responsibility: $responsibility,
            technicalReason: $technicalReason,
            recommendedAction: $recommendedAction,
            adminAction: $adminAction,
        );
    }
}
