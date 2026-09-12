<?php

namespace App\Http\Controllers\Checkout;

use App\Domains\Billing\Models\Plan;
use App\Domains\Payments\Models\Payment;
use App\Domains\Payments\Models\PaymentTransaction;
use App\Domains\Payments\Services\CheckoutService;
use App\Domains\Payments\Services\PaymentGatewayRegistry;
use App\Domains\Tax\Models\CustomerTaxProfile;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * The customer's side of paying (§20, Addendum D §2).
 *
 * ONE BRANDED FLOW WHATEVER THE GATEWAY DOES. A modal opens over this page, a
 * redirect leaves and comes back to it — the customer sees Aziv AI either way,
 * which is what makes switching gateway invisible to them.
 *
 * The return page never decides anything. It says "checking with your bank"
 * and asks the server, which asks the gateway. A URL that claims success
 * proves nothing.
 */
class CheckoutController extends Controller
{
    public function start(Request $request, Plan $plan, CheckoutService $checkout): View|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $currency = strtoupper((string) ($request->input('currency') ?: settings('billing.base_currency')));

        $country = CustomerTaxProfile::where('user_id', $user->getKey())->value('country');

        try {
            ['payment' => $payment, 'session' => $session] = $checkout->start($user, $plan, $currency, $country);
        } catch (RuntimeException $e) {
            // Never a blank failure: the selector's own reason says whether
            // this is a configuration problem or a currency the owner does not
            // sell in.
            return redirect()->route('pricing')->with('error', $e->getMessage());
        }

        return view('billing.checkout', [
            'plan' => $plan,
            'payment' => $payment,
            'session' => $session,
            'currency' => $currency,
            'returnUrl' => route('checkout.return', $payment),
            'statusUrl' => route('checkout.status', $payment),
        ]);
    }

    /**
     * Where the customer lands after paying.
     *
     * Verifies what the browser handed back — which proves it was not
     * tampered with — and then IGNORES IT as evidence of payment, settling
     * from the gateway instead.
     */
    public function return(Request $request, Payment $payment, CheckoutService $checkout, PaymentGatewayRegistry $registry): View
    {
        abort_unless($payment->user_id === $request->user()?->getKey(), 403);

        $adapter = $registry->for($payment->gateway);
        $returned = $request->all();

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

        return view('billing.checkout-result', ['payment' => $settled->fresh(['plan', 'invoice'])]);
    }

    /** Polled by the result page while a payment is still settling. */
    public function status(Request $request, Payment $payment, CheckoutService $checkout)
    {
        abort_unless($payment->user_id === $request->user()?->getKey(), 403);

        if (! $payment->isPaid()) {
            $payment = $checkout->settle($payment, PaymentTransaction::RECONCILED);
        }

        return response()->json([
            'status' => $payment->status,
            'paid' => $payment->isPaid(),
            'invoice' => $payment->invoice?->number,
        ]);
    }
}
