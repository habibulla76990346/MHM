<?php

namespace App\Domains\Payments\Contracts;

use App\Domains\Payments\DTO\CheckoutRequest;
use App\Domains\Payments\DTO\CheckoutSession;
use App\Domains\Payments\DTO\GatewayTestResult;
use App\Domains\Payments\DTO\TransactionStatus;
use App\Domains\Payments\DTO\WebhookVerification;
use App\Domains\Payments\Models\PaymentGatewayRecord;

/**
 * The boundary every gateway sits behind (Addendum D §2).
 *
 * NOTHING ABOVE THIS INTERFACE KNOWS WHICH COMPANY TOOK THE MONEY. Plans,
 * subscriptions, invoices, credits and checkout hold a gateway id and call
 * these methods; adding a sixth gateway is a class and a database row.
 *
 * `fetchTransaction()` is the authoritative one. A customer's browser is not
 * evidence, and neither is a webhook until its signature has been checked —
 * both converge on asking the gateway directly.
 */
interface PaymentGateway
{
    /** The stable key this adapter is registered under. */
    public function key(): string;

    /** Bind this adapter to a configured gateway row. */
    public function forGateway(PaymentGatewayRecord $gateway): static;

    /** @return array<int, string> capabilities genuinely supported */
    public function capabilities(): array;

    public function supports(string $capability): bool;

    /** @return array<int, string> ISO-4217 codes, or [] for "whatever the account allows" */
    public function supportedCurrencies(): array;

    /** @return array<int, string> ISO-3166 codes, or [] for no restriction */
    public function supportedCountries(): array;

    public function checkoutMode(): string;

    public function createCheckout(CheckoutRequest $request): CheckoutSession;

    /**
     * Check what came back on the customer's return.
     *
     * Verifying this proves the browser was not tampered with. It does NOT
     * prove payment — that still takes `fetchTransaction()`.
     */
    public function verifyReturn(array $payload, array $headers): WebhookVerification;

    /**
     * Verify a webhook against the RAW request body.
     *
     * The bytes as received, before any JSON parsing: re-encoding changes
     * whitespace and key order, the signature then fails, and the usual
     * "fix" is to stop verifying.
     */
    public function verifyWebhook(string $rawBody, array $headers): WebhookVerification;

    /** The authority on whether money moved. */
    public function fetchTransaction(string $gatewayReference): TransactionStatus;

    public function testConnection(): GatewayTestResult;
}
