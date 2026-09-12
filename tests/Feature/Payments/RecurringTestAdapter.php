<?php

namespace Tests\Feature\Payments;

use App\Domains\Payments\Adapters\BaseGatewayAdapter;
use App\Domains\Payments\Contracts\SupportsOneTimePayment;
use App\Domains\Payments\Contracts\SupportsSubscriptions;
use App\Domains\Payments\DTO\CheckoutRequest;
use App\Domains\Payments\DTO\CheckoutSession;
use App\Domains\Payments\DTO\GatewayTestResult;
use App\Domains\Payments\DTO\TransactionStatus;
use App\Domains\Payments\DTO\WebhookVerification;
use App\Domains\Payments\Support\Capability;
use App\Domains\Payments\Support\CheckoutMode;
use Illuminate\Http\Client\PendingRequest;

/** A processor that can also renew a subscription by itself. */
class RecurringTestAdapter extends BaseGatewayAdapter implements SupportsOneTimePayment, SupportsSubscriptions
{
    public function key(): string
    {
        return 'recurring';
    }

    public function capabilities(): array
    {
        return [Capability::ONE_TIME, Capability::RECURRING];
    }

    public function checkoutMode(): string
    {
        return CheckoutMode::REDIRECT;
    }

    protected function client(): PendingRequest
    {
        return $this->baseClient();
    }

    public function createCheckout(CheckoutRequest $request): CheckoutSession
    {
        return new CheckoutSession('ref_'.$request->reference, $this->checkoutMode(), 'https://example.test/pay');
    }

    public function verifyReturn(array $payload, array $headers): WebhookVerification
    {
        return WebhookVerification::rejected('not implemented in tests');
    }

    public function verifyWebhook(string $rawBody, array $headers): WebhookVerification
    {
        return WebhookVerification::rejected('not implemented in tests');
    }

    public function fetchTransaction(string $gatewayReference): TransactionStatus
    {
        return new TransactionStatus(TransactionStatus::PENDING);
    }

    public function testConnection(): GatewayTestResult
    {
        return GatewayTestResult::pass(1);
    }
}
