<?php

namespace App\Http\Controllers\Billing;

use App\Domains\Billing\Models\Country;
use App\Domains\Billing\Models\Invoice;
use App\Domains\Billing\Models\Subscription;
use App\Domains\Billing\Services\EntitlementService;
use App\Domains\Billing\Services\RenewalService;
use App\Domains\Billing\Services\SubscriptionService;
use App\Domains\Billing\Support\RenewalNotice;
use App\Domains\Credits\Models\CreditLedgerEntry;
use App\Domains\Credits\Services\CreditService;
use App\Domains\Tax\Models\CustomerTaxProfile;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The customer's own billing page (§20).
 *
 * Their plan, what is left of their credits, where those credits went, and
 * their invoices. The billing details they enter here are what an invoice
 * copies at issue time, so the form says that plainly rather than letting them
 * discover it after a document is final.
 */
class BillingController extends Controller
{
    public function show(
        EntitlementService $entitlements,
        CreditService $credits,
        SubscriptionService $subscriptions,
        RenewalService $renewals,
    ): View {
        $user = auth()->user();

        // Lands them on the default plan if they have never had one, so this
        // page is never empty for an account created before billing existed.
        $subscriptions->ensureSubscription($user);

        $balance = $credits->balance($user);

        return view('billing.index', [
            // A customer must always have a way to renew that does not depend
            // on finding an email. Prepared on the way in, so the page shows
            // the same invoice and the same payment the notice carried —
            // never a second one (the guard is a unique index, so asking here
            // is safe however many times the page is opened).
            'renewal' => $this->outstandingRenewal($renewals, $entitlements->subscription($user)),
            'subscription' => $entitlements->subscription($user),
            'plan' => $entitlements->plan($user),
            'balance' => $balance,
            'metering' => $entitlements->meteringIsActive(),
            'history' => CreditLedgerEntry::where('user_id', $user->getKey())
                ->orderByDesc('id')
                ->limit(25)
                ->get(),
            'invoices' => Invoice::where('user_id', $user->getKey())
                ->whereNotNull('issued_at')
                ->orderByDesc('issued_at')
                ->limit(25)
                ->get(),
            'profile' => CustomerTaxProfile::firstOrNew(['user_id' => $user->getKey()]),
            'countries' => Country::where('is_billing_enabled', true)
                ->orderBy('name')
                ->get(),
        ]);
    }

    /**
     * The renewal this customer owes, if there is one.
     *
     * Only when it is genuinely due: preparing a renewal months early would
     * issue an invoice — and burn an invoice number — for a period nobody has
     * reached. The notice window the owner configured decides when that is,
     * so the page and the email agree.
     */
    private function outstandingRenewal(RenewalService $renewals, ?Subscription $subscription): ?RenewalNotice
    {
        if (! $subscription || $subscription->renewal_mechanism !== 'manual' || $subscription->cancelled_at) {
            return null;
        }

        $due = $subscription->current_period_end?->isBefore(
            now()->addDays((int) settings('billing.renewal_notice_days'))
        );

        if (! $due) {
            return null;
        }

        $notice = $renewals->prepare($subscription);

        return $notice && $notice->invoice->isOutstanding() ? $notice : null;
    }

    /**
     * Save the billing details that will be copied onto future invoices.
     *
     * Changing them NEVER alters an invoice already issued — that document
     * carries its own copy. The form says so, because a customer who corrects
     * a typo expects their old invoices to change and needs to know they will
     * not.
     */
    public function updateProfile(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'billing_name' => ['nullable', 'string', 'max:255'],
            'billing_address' => ['nullable', 'string', 'max:1000'],
            'country' => ['nullable', 'string', 'size:2'],
            'state' => ['nullable', 'string', 'max:64'],
            'postal_code' => ['nullable', 'string', 'max:24'],
            'tax_registration_number' => ['nullable', 'string', 'max:64'],
            'is_business' => ['nullable', 'boolean'],
        ]);

        CustomerTaxProfile::updateOrCreate(
            ['user_id' => auth()->id()],
            array_merge($data, ['is_business' => (bool) ($data['is_business'] ?? false)]),
        );

        return back()->with('status', __('Your billing details are saved. Invoices already issued keep the details they were issued with.'));
    }

    public function invoice(Invoice $invoice): View
    {
        // Ownership compared directly rather than through the Gate: Spatie's
        // Gate::before would otherwise let an administrator open a customer's
        // invoice through the customer-facing route, bypassing the audit trail
        // that the admin screen leaves.
        abort_unless($invoice->user_id === auth()->id(), 403);
        abort_unless($invoice->isIssued(), 404);

        $invoice->load(['lines', 'taxLines', 'creditNotes']);

        return view('billing.invoice', ['invoice' => $invoice]);
    }
}
