<?php

namespace App\Domains\Payments\Adapters;

use App\Domains\Payments\Contracts\SupportsOneTimePayment;
use App\Domains\Payments\Contracts\SupportsRefunds;
use App\Domains\Payments\DTO\CheckoutRequest;
use App\Domains\Payments\DTO\CheckoutSession;
use App\Domains\Payments\DTO\GatewayTestResult;
use App\Domains\Payments\DTO\RefundRequest;
use App\Domains\Payments\DTO\RefundResult;
use App\Domains\Payments\DTO\TransactionStatus;
use App\Domains\Payments\DTO\WebhookVerification;
use App\Domains\Payments\Exceptions\GatewayFailed;
use App\Domains\Payments\Support\Capability;
use App\Domains\Payments\Support\CheckoutMode;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Str;

/**
 * A gateway that takes no money and calls nothing (development only).
 *
 * WHY IT EXISTS. Two things need a checkout page that renders without a
 * merchant account: the six-viewport gate, which has to prove checkout works
 * at 320px, and an owner who wants to walk the whole flow before wiring a real
 * account to it. Both were previously impossible to do honestly — a screenshot
 * of a page that errored is not a checked screen.
 *
 * WHY IT IS SAFE. It refuses to operate outside local and testing
 * environments, at every entry point rather than at one. A gateway that
 * reported success without taking money would, in production, be a way to get
 * credits for nothing — so it does not run there at all, and no configuration
 * can make it.
 */
class FixtureGatewayAdapter extends BaseGatewayAdapter implements SupportsOneTimePayment, SupportsRefunds
{
    public const KEY = 'fixture';

    public function key(): string
    {
        return self::KEY;
    }

    public function capabilities(): array
    {
        return [Capability::ONE_TIME, Capability::REFUNDS];
    }

    public function checkoutMode(): string
    {
        return CheckoutMode::SDK_MODAL;
    }

    protected function client(): PendingRequest
    {
        return $this->baseClient();
    }

    public function createCheckout(CheckoutRequest $request): CheckoutSession
    {
        $this->refuseOutsideDevelopment();

        return new CheckoutSession(
            gatewayReference: 'fixture_'.Str::random(12),
            mode: $this->checkoutMode(),
            publicConfig: [
                'key' => 'fixture-publishable',
                'order_id' => 'fixture_order_'.Str::random(8),
                'currency' => $request->currency,
                'name' => (string) settings('branding.app_name'),
                'description' => $request->description,
            ],
            orderId: 'fixture_order_'.Str::random(8),
        );
    }

    public function verifyReturn(array $payload, array $headers): WebhookVerification
    {
        $this->refuseOutsideDevelopment();

        return WebhookVerification::rejected('The development gateway does not verify returns.');
    }

    public function verifyWebhook(string $rawBody, array $headers): WebhookVerification
    {
        $this->refuseOutsideDevelopment();

        return WebhookVerification::rejected('The development gateway does not accept webhooks.');
    }

    /**
     * Always pending, never paid.
     *
     * Deliberate: a development gateway that reported PAID would grant credits
     * for money nobody sent, and the only thing standing between that and
     * production would be a configuration row.
     */
    public function fetchTransaction(string $gatewayReference): TransactionStatus
    {
        $this->refuseOutsideDevelopment();

        return new TransactionStatus(TransactionStatus::PENDING, $gatewayReference);
    }

    public function refund(RefundRequest $request): RefundResult
    {
        $this->refuseOutsideDevelopment();

        return new RefundResult(RefundResult::FAILED, failureReason: 'The development gateway cannot refund.');
    }

    public function testConnection(): GatewayTestResult
    {
        return app()->environment(['local', 'testing'])
            ? GatewayTestResult::pass(0)
            : GatewayTestResult::fail(
                GatewayFailed::NOT_PERMITTED,
                'This is a development-only gateway and does nothing outside a local environment. Switch it off.',
            );
    }

    private function refuseOutsideDevelopment(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new GatewayFailed(GatewayFailed::NOT_PERMITTED);
        }
    }
}
