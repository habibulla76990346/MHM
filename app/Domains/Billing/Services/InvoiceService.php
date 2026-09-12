<?php

namespace App\Domains\Billing\Services;

use App\Domains\Billing\Models\CreditNote;
use App\Domains\Billing\Models\Invoice;
use App\Domains\Billing\Models\InvoiceLine;
use App\Domains\Billing\Models\InvoiceTaxLine;
use App\Domains\Tax\Models\CustomerTaxProfile;
use App\Domains\Tax\Models\TaxSettings;
use App\Domains\Tax\Services\TaxEngine;
use App\Domains\Tax\Support\TaxComputation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Building and issuing invoices (§20, Addendum F).
 *
 * A DRAFT is a working document: lines can be added, tax recomputed, totals
 * change. ISSUING is a one-way door — it allocates a number, copies every
 * identity and tax figure onto the row, and the model layer then refuses any
 * further change.
 *
 * The copying is the whole design. Nothing on an issued invoice is looked up
 * again when it is displayed, so editing the business address, a customer's
 * details or a tax rate tomorrow cannot alter a document already sent.
 */
class InvoiceService
{
    public function __construct(
        private readonly TaxEngine $tax,
        private readonly InvoiceNumberAllocator $numbers,
    ) {}

    /**
     * Start a draft.
     *
     * @param  array<int, array{description: string, quantity?: float, unit_amount: float, discount_amount?: float, service_code?: string}>  $lines
     * @param  \DateTimeInterface|null  $renewalPeriodStart  the period this invoice renews, for the once-only guard
     */
    public function draft(
        User $user,
        string $currency,
        array $lines,
        ?int $subscriptionId = null,
        ?\DateTimeInterface $renewalPeriodStart = null,
    ): Invoice {
        return DB::transaction(function () use ($user, $currency, $lines, $subscriptionId, $renewalPeriodStart) {
            $invoice = Invoice::create([
                'user_id' => $user->getKey(),
                'subscription_id' => $subscriptionId,
                // Stamped here and nowhere else. Together with the unique
                // index on (subscription, renewal period) this is what makes
                // "one invoice per period" a fact the database enforces
                // rather than a check two processes can both pass.
                'renewal_period_start' => $renewalPeriodStart,
                'status' => Invoice::STATUS_DRAFT,
                'currency' => strtoupper($currency),
            ]);

            foreach (array_values($lines) as $index => $line) {
                $quantity = (float) ($line['quantity'] ?? 1);
                $unit = (float) $line['unit_amount'];
                $discount = (float) ($line['discount_amount'] ?? 0);

                InvoiceLine::create([
                    'invoice_id' => $invoice->getKey(),
                    'description' => $line['description'],
                    'quantity' => $quantity,
                    'unit_amount' => $unit,
                    'discount_amount' => $discount,
                    'line_total' => round($quantity * $unit - $discount, 6),
                    'service_code' => $line['service_code'] ?? null,
                    'sort_order' => $index,
                ]);
            }

            return $invoice->fresh('lines');
        });
    }

    /**
     * Issue it: allocate a number, freeze the computation, close the door.
     *
     * @throws RuntimeException if it has already been issued
     */
    public function issue(Invoice $invoice, ?\DateTimeInterface $issuedAt = null): Invoice
    {
        if ($invoice->isIssued()) {
            throw new RuntimeException('Invoice '.$invoice->number.' has already been issued.');
        }

        $issuedAt ??= now();

        return DB::transaction(function () use ($invoice, $issuedAt) {
            $settings = TaxSettings::current();
            $profile = CustomerTaxProfile::where('user_id', $invoice->user_id)->first();

            $subtotal = round((float) $invoice->lines()->sum('line_total'), 6);
            $discount = round((float) $invoice->lines()->sum('discount_amount'), 6);

            // Computed at the INVOICE DATE, so reissuing this document later
            // produces the identical figures.
            $computation = $this->tax->compute($subtotal, $profile, $issuedAt);

            $this->freezeTaxLines($invoice, $computation);

            $user = $invoice->user;

            $invoice->forceFill([
                'number' => $this->numbers->next($issuedAt),
                'status' => Invoice::STATUS_ISSUED,
                'subtotal' => $computation->net,
                'discount_total' => $discount,
                'tax_total' => $computation->taxTotal,
                'total' => $computation->gross,

                // --- the snapshot. Copies, never references.
                'supplier_legal_name' => $settings->legal_name,
                'supplier_address' => $settings->addressBlock(),
                'supplier_tax_number' => $settings->tax_registration_number,
                'customer_name' => $profile?->billing_name ?: $user?->name,
                'customer_address' => $profile?->billing_address,
                'customer_country' => $profile?->country ?: $settings->country,
                'customer_state' => $profile?->state,
                'customer_tax_number' => $profile?->tax_registration_number,
                'place_of_supply' => $computation->placeOfSupply,
                'service_code' => $settings->service_code,
                'pricing_mode' => $computation->pricingMode,
                'is_export' => $computation->isExport,
                'tax_note' => $computation->note,
                'issued_at' => $issuedAt,
            ])->save();

            return $invoice->fresh(['lines', 'taxLines']);
        });
    }

    public function markPaid(Invoice $invoice, ?\DateTimeInterface $paidAt = null): Invoice
    {
        // Status and paid_at are the only columns that may move after issue:
        // being paid happens TO an invoice, it does not change what it says.
        $invoice->forceFill([
            'status' => Invoice::STATUS_PAID,
            'paid_at' => $paidAt ?? now(),
        ])->save();

        return $invoice;
    }

    /**
     * Correct an issued invoice the only way accounting allows.
     *
     * Never an edit. The credit note carries its own number and its own copy of
     * the tax lines, so it stands on its own even if the invoice is archived.
     */
    public function creditNote(Invoice $invoice, float $amount, string $reason, ?int $actorId = null): CreditNote
    {
        if (! $invoice->isIssued()) {
            throw new RuntimeException('A draft invoice is corrected by editing it, not by a credit note.');
        }

        if (trim($reason) === '') {
            throw new RuntimeException('A credit note must carry a reason.');
        }

        $alreadyCredited = (float) $invoice->creditNotes()->sum('amount');
        $remaining = round((float) $invoice->total - $alreadyCredited, 6);

        if ($amount <= 0 || $amount > $remaining + 0.000001) {
            throw new RuntimeException('A credit note cannot exceed what is left on the invoice.');
        }

        return DB::transaction(function () use ($invoice, $amount, $reason, $actorId, $remaining) {
            $issuedAt = now();

            // The proportion of the invoice being credited decides how much of
            // its tax is credited with it — crediting the net and keeping the
            // tax would leave the business paying tax on money it refunded.
            $share = (float) $invoice->total > 0 ? $amount / (float) $invoice->total : 0.0;

            $note = CreditNote::create([
                'invoice_id' => $invoice->getKey(),
                'number' => $this->numbers->next($issuedAt, 'credit_note'),
                'reason' => $reason,
                'amount' => round($amount, 6),
                'tax_amount' => round((float) $invoice->tax_total * $share, 6),
                'currency' => $invoice->currency,
                'tax_snapshot' => $invoice->taxLines->map(fn (InvoiceTaxLine $line) => [
                    'name' => $line->component_name,
                    'code' => $line->component_code,
                    'rate_percent' => (float) $line->rate_percent,
                    'tax_amount' => round((float) $line->tax_amount * $share, 6),
                ])->all(),
                'issued_by' => $actorId,
                'issued_at' => $issuedAt,
            ]);

            // Fully credited means the invoice no longer stands. It is VOIDED,
            // not deleted: both documents have to survive.
            if (abs($amount - $remaining) < 0.000001) {
                $invoice->forceFill(['status' => Invoice::STATUS_VOID])->save();
            }

            return $note;
        });
    }

    /** @return array<int, InvoiceTaxLine> */
    private function freezeTaxLines(Invoice $invoice, TaxComputation $computation): array
    {
        $lines = [];

        foreach ($computation->components as $index => $component) {
            $lines[] = InvoiceTaxLine::create([
                'invoice_id' => $invoice->getKey(),
                'component_name' => $component['name'],
                'component_code' => $component['code'],
                'rate_percent' => $component['rate_percent'],
                'taxable_amount' => $component['taxable_amount'],
                'tax_amount' => $component['tax_amount'],
                'jurisdiction_name' => $component['jurisdiction'],
                'sort_order' => $index,
            ]);
        }

        return $lines;
    }
}
