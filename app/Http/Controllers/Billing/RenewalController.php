<?php

namespace App\Http\Controllers\Billing;

use App\Domains\Payments\Models\Payment;
use App\Domains\Payments\Models\PaymentTransaction;
use App\Domains\Payments\Services\CheckoutService;
use App\Domains\Payments\Services\PaymentGatewayRegistry;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;
use RuntimeException;

/**
 * Paying a renewal invoice from the link in the email (Addendum D §3).
 *
 * THE SIGNATURE IS THE AUTHORISATION, and it is the only one. These routes sit
 * outside the auth group deliberately: the whole purpose of the link is to
 * work for somebody reading their email on a phone they have never signed in
 * on. The proof is an HMAC over the URL under the application key — the same
 * mechanism as the email-verification link, which is chosen rather than
 * invented so there is one way of proving "this person received our email".
 *
 * WHAT THE PAGE IS ALLOWED TO SAY is therefore deliberately thin: the plan,
 * the amount, the invoice number and the date. No name, no address, no email,
 * no account. Everything an account holder can see stays behind signing in,
 * because a forwarded email must not become a window into somebody's account.
 *
 * IT SETTLES NOTHING ITSELF. Like every other path, it asks
 * `CheckoutService::settle()`, which asks the gateway. A URL that claims
 * success proves nothing here either.
 */
class RenewalController extends Controller
{
    /** The invoice, the amount, and a button. Nothing else. */
    public function show(Payment $payment): View
    {
        $this->guard($payment);

        return view('billing.renew', [
            'payment' => $payment,
            'invoice' => $payment->invoice,
            'plan' => $payment->plan,
            'paid' => $payment->isPaid(),
        ]);
    }

    /**
     * Open the gateway for this renewal.
     *
     * A separate step from opening the link on purpose: creating the gateway's
     * order when the email is merely previewed — by a scanner, a prefetcher,
     * an over-eager mail client — would leave a trail of orders nobody
     * intended, and most gateways expire them.
     */
    public function pay(Payment $payment, CheckoutService $checkout): View
    {
        $this->guard($payment);

        if ($payment->isPaid()) {
            return $this->show($payment);
        }

        try {
            $session = $checkout->openCheckout($payment, (string) ($payment->plan?->name ?: __('Renewal')), $this->returnUrl($payment));
        } catch (RuntimeException $e) {
            return view('billing.renew', [
                'payment' => $payment,
                'invoice' => $payment->invoice,
                'plan' => $payment->plan,
                'paid' => false,
                'error' => $e->getMessage(),
            ]);
        }

        return view('billing.checkout', [
            'plan' => $payment->plan,
            'payment' => $payment,
            'session' => $session,
            'currency' => $payment->presentment_currency,
            'returnUrl' => $this->returnUrl($payment),
            'statusUrl' => URL::signedRoute('renewal.status', ['payment' => $payment->uuid]),
        ]);
    }

    /** Where the gateway sends the browser back to. */
    public function return(Request $request, Payment $payment, CheckoutService $checkout, PaymentGatewayRegistry $registry): View
    {
        $this->guard($payment, allowPaid: true);

        $adapter = $registry->for($payment->gateway);
        $returned = $request->except(['signature', 'expires']);

        if ($adapter && $returned !== []) {
            $verification = $adapter->verifyReturn($returned, $request->headers->all());

            if ($verification->valid && $verification->gatewayPaymentId) {
                $payment->forceFill(['gateway_payment_id' => $verification->gatewayPaymentId])->save();
            }

            $checkout->record($payment, PaymentTransaction::RETURNED, [
                'signature_valid' => $verification->valid,
            ]);
        }

        $settled = $checkout->settle($payment, PaymentTransaction::RETURNED);

        return view('billing.renew', [
            'payment' => $settled->fresh(['plan', 'invoice']),
            'invoice' => $settled->invoice,
            'plan' => $settled->plan,
            'paid' => $settled->fresh()->isPaid(),
        ]);
    }

    /** Polled while a payment is still settling. */
    public function status(Payment $payment, CheckoutService $checkout)
    {
        $this->guard($payment, allowPaid: true);

        if (! $payment->isPaid()) {
            $payment = $checkout->settle($payment, PaymentTransaction::RECONCILED);
        }

        // The invoice number is on the link's own invoice, so it tells the
        // holder nothing they were not already sent.
        return response()->json([
            'status' => $payment->status,
            'paid' => $payment->isPaid(),
            'invoice' => $payment->invoice?->number,
        ]);
    }

    /**
     * A signed link opens ONE renewal, and only a renewal.
     *
     * The signature already proves the uuid was not edited. This adds the
     * thing a signature cannot know: that the payment is the kind of thing
     * these public routes are for. Without it, a signed URL generated for a
     * renewal could be pointed at an ordinary checkout payment, whose result
     * page belongs behind a login.
     */
    private function guard(Payment $payment, bool $allowPaid = false): void
    {
        abort_unless($payment->isRenewal(), 404);

        if (! $allowPaid && $payment->status === Payment::STATUS_REFUNDED) {
            abort(404);
        }
    }

    private function returnUrl(Payment $payment): string
    {
        // Not time-limited: a bank redirect can take longer than anyone
        // expects, and a customer landing on an expired page after paying is
        // the worst possible moment to show them an error. It grants nothing
        // but the status of one payment.
        return URL::signedRoute('renewal.return', ['payment' => $payment->uuid]);
    }
}
