<?php

namespace App\Domains\Payments\Services;

use App\Domains\Billing\Services\InvoiceService;
use App\Domains\Credits\Models\CreditLedgerEntry;
use App\Domains\Credits\Services\CreditService;
use App\Domains\Payments\Contracts\SupportsPartialRefunds;
use App\Domains\Payments\Contracts\SupportsRefunds;
use App\Domains\Payments\DTO\RefundRequest;
use App\Domains\Payments\DTO\RefundResult;
use App\Domains\Payments\Exceptions\GatewayFailed;
use App\Domains\Payments\Models\Payment;
use App\Domains\Payments\Models\PaymentTransaction;
use App\Domains\Payments\Models\Refund;
use App\Domains\Security\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Refunds, and what they do to the credits the payment granted.
 *
 * THE OWNER'S DECISION, implemented literally: take back what is UNSPENT, up
 * to the refunded amount, and never below zero. Credits the customer already
 * used cost real provider money and cannot be recovered by arithmetic;
 * trapping an honest customer at a negative balance would punish somebody who
 * may have had a good reason to ask.
 *
 * A refund also produces a CREDIT NOTE against the invoice rather than editing
 * it — an issued invoice never changes.
 */
class RefundService
{
    public function __construct(
        private readonly PaymentGatewayRegistry $registry,
        private readonly CreditService $credits,
        private readonly InvoiceService $invoices,
    ) {}

    /**
     * @throws RuntimeException when the gateway cannot do it, or the amount is wrong
     */
    public function refund(Payment $payment, float $amount, string $reason, ?int $actorId = null): Refund
    {
        if (! $payment->isPaid()) {
            throw new RuntimeException(__('Only a paid payment can be refunded.'));
        }

        if (trim($reason) === '') {
            throw new RuntimeException(__('A refund must carry a reason.'));
        }

        $adapter = $this->registry->for($payment->gateway);

        if (! $adapter instanceof SupportsRefunds) {
            // Loudly, not silently: an owner who thinks a refund happened and
            // finds out weeks later that it did not has a much worse problem.
            throw new RuntimeException(__('This gateway cannot process refunds. Refund it in the gateway\'s own dashboard and record it here.'));
        }

        $refundable = $payment->refundable();

        if ($amount <= 0 || $amount > $refundable + 0.000001) {
            throw new RuntimeException(__('At most :amount can still be refunded on this payment.', [
                'amount' => number_format($refundable, 2),
            ]));
        }

        $isPartial = $amount < (float) $payment->presentment_amount - 0.000001;

        if ($isPartial && ! $adapter instanceof SupportsPartialRefunds) {
            throw new RuntimeException(__('This gateway can only refund a payment in full.'));
        }

        try {
            $result = $adapter->refund(new RefundRequest(
                gatewayPaymentId: (string) $payment->gateway_payment_id,
                amount: $amount,
                currency: (string) $payment->presentment_currency,
                reason: $reason,
                reference: $payment->uuid,
            ));
        } catch (GatewayFailed $e) {
            throw new RuntimeException($e->action());
        }

        if (! $result->succeeded()) {
            throw new RuntimeException(__('The gateway did not accept the refund.'));
        }

        return DB::transaction(function () use ($payment, $amount, $reason, $actorId, $result) {
            $revoked = $this->credits->revoke(
                $payment->user,
                $amount === (float) $payment->presentment_amount
                    ? $this->creditsGrantedBy($payment)
                    : $this->creditsGrantedBy($payment) * ($amount / (float) $payment->presentment_amount),
                __('Refund of payment :ref', ['ref' => $payment->uuid]),
                'payment',
                (string) $payment->getKey(),
            );

            $refund = Refund::create([
                'payment_id' => $payment->getKey(),
                'gateway_id' => $payment->gateway_id,
                'gateway_refund_id' => $result->gatewayRefundId,
                'amount' => $amount,
                'currency' => $payment->presentment_currency,
                'status' => $result->status === RefundResult::COMPLETED ? Refund::COMPLETED : Refund::PENDING,
                'reason' => $reason,
                'credits_revoked' => $revoked,
                'requested_by' => $actorId,
                'processed_at' => $result->status === RefundResult::COMPLETED ? now() : null,
            ]);

            $payment->forceFill([
                'status' => $payment->refundedTotal() >= (float) $payment->presentment_amount - 0.000001
                    ? Payment::STATUS_REFUNDED
                    : Payment::STATUS_PARTIALLY_REFUNDED,
            ])->save();

            // The invoice is corrected the way accounting requires.
            if ($payment->invoice && $payment->invoice->isIssued() && $payment->invoice->outstanding() > 0) {
                $this->invoices->creditNote(
                    $payment->invoice,
                    min($amount, $payment->invoice->outstanding()),
                    $reason,
                    $actorId,
                );
            }

            app(ActivityLogger::class)->log('payments.refunded', $payment, null, [
                'amount' => $amount,
                'currency' => $payment->presentment_currency,
                'reason' => $reason,
                'credits_revoked' => $revoked,
            ]);

            PaymentTransaction::create([
                'payment_id' => $payment->getKey(),
                'gateway_id' => $payment->gateway_id,
                'gateway_transaction_id' => $result->gatewayRefundId,
                'type' => PaymentTransaction::REFUNDED,
                'amount' => $amount,
                'currency' => $payment->presentment_currency,
                'status' => $refund->status,
                'raw_payload' => ['reason' => $reason],
            ]);

            return $refund;
        });
    }

    /**
     * How many credits this payment put into the account.
     *
     * Read from the ledger rather than recomputed from the plan: the plan's
     * allowance may have been edited since, and what was actually granted is
     * the only honest basis for taking any of it back.
     */
    private function creditsGrantedBy(Payment $payment): float
    {
        $periodIds = $payment->subscription?->periods()->pluck('id') ?? collect();

        if ($periodIds->isEmpty()) {
            return 0.0;
        }

        return (float) CreditLedgerEntry::where('user_id', $payment->user_id)
            ->where('reference_type', 'subscription_period')
            ->whereIn('reference_id', $periodIds->map(fn ($id) => (string) $id))
            ->where('amount', '>', 0)
            ->sum('amount');
    }
}
