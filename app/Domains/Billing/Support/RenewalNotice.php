<?php

namespace App\Domains\Billing\Support;

use App\Domains\Billing\Models\Invoice;
use App\Domains\Billing\Models\Subscription;
use App\Domains\Payments\Models\Payment;
use Illuminate\Support\Carbon;

/**
 * Everything a customer needs in order to renew: the bill, the payment it
 * belongs to, and the link that opens it.
 *
 * `isNew` says whether this call created the paperwork or found it already
 * there. The scheduler and an administrator pressing "send the renewal now"
 * both reach the same period, and the second one must not raise a second
 * invoice — so "already existed" is a normal outcome, not a failure.
 */
final class RenewalNotice
{
    public function __construct(
        public readonly Subscription $subscription,
        public readonly Invoice $invoice,
        public readonly Payment $payment,
        public readonly string $payUrl,
        public readonly Carbon $periodStart,
        public readonly Carbon $periodEnd,
        public readonly bool $isNew,
    ) {}
}
