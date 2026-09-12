<?php

namespace App\Domains\Payments\Services;

use App\Domains\Payments\Models\Payment;
use App\Domains\Payments\Models\PaymentTransaction;
use Illuminate\Support\Collection;

/**
 * The safety net (Addendum D §4.1).
 *
 * A customer pays, closes the tab before the return, and the webhook is
 * delayed or lost. Without this, money has been taken and nothing has been
 * delivered — and the first anyone hears of it is a support ticket.
 *
 * The sweep asks the gateway about every payment still waiting past a
 * threshold. It goes through the same `settle()` as every other path, so a
 * payment that a webhook settles a second later is already done and the sweep
 * changes nothing.
 */
class ReconciliationService
{
    public function __construct(private readonly CheckoutService $checkout) {}

    /**
     * @return array{checked: int, settled: int}
     */
    public function sweep(int $olderThanMinutes = 5, int $limit = 100): array
    {
        $checked = 0;
        $settled = 0;

        Payment::query()
            ->whereIn('status', [Payment::STATUS_CREATED, Payment::STATUS_PENDING])
            ->where('created_at', '<', now()->subMinutes($olderThanMinutes))
            // Abandoned checkouts are the common case; there is no point
            // asking about one for ever.
            ->where('created_at', '>', now()->subDays(7))
            ->whereNotNull('gateway_id')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (Payment $payment) use (&$checked, &$settled) {
                $checked++;

                $result = $this->checkout->settle($payment, PaymentTransaction::RECONCILED);

                if ($result->isPaid()) {
                    $settled++;
                }
            });

        return ['checked' => $checked, 'settled' => $settled];
    }

    /**
     * Payments whose state here and at the gateway may disagree.
     *
     * The list behind the admin reconciliation screen: anything still waiting
     * long after it should have resolved, which is where a lost webhook shows
     * up as a customer who paid and got nothing.
     *
     * @return Collection<int, Payment>
     */
    public function needingAttention(int $olderThanMinutes = 30)
    {
        return Payment::with(['user', 'gateway', 'plan'])
            ->whereIn('status', [Payment::STATUS_CREATED, Payment::STATUS_PENDING])
            ->where('created_at', '<', now()->subMinutes($olderThanMinutes))
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();
    }
}
