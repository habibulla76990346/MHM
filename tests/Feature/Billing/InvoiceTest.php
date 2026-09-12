<?php

namespace Tests\Feature\Billing;

use App\Domains\Billing\Models\Invoice;
use App\Domains\Billing\Models\InvoiceNumberSequence;
use App\Domains\Billing\Services\InvoiceNumberAllocator;
use App\Domains\Billing\Services\InvoiceService;
use App\Domains\Tax\Models\TaxJurisdiction;
use App\Domains\Tax\Models\TaxRate;
use App\Domains\Tax\Models\TaxRule;
use App\Domains\Tax\Models\TaxSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * The Phase 6 gate for invoices, and the owner's most important requirement:
 * **an issued invoice never changes when configuration changes.**
 */
class InvoiceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private InvoiceService $invoices;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['name' => 'A Customer']);
        $this->invoices = app(InvoiceService::class);
    }

    private function configureTax(float $percent = 18): TaxRate
    {
        TaxSettings::current()->forceFill([
            'legal_name' => 'Test Business Pvt Ltd',
            'address_lines' => '1 Example Road',
            'country' => 'IN',
            'state' => 'Karnataka',
            'tax_registration_number' => 'TESTREG0001',
            'default_place_of_supply' => 'Karnataka',
            'tax_enabled' => true,
            'pricing_mode' => TaxSettings::EXCLUSIVE,
        ])->save();

        $jurisdiction = TaxJurisdiction::create([
            'name' => 'Domestic', 'country' => 'IN', 'is_domestic' => true,
            'priority' => 50, 'is_active' => true,
        ]);

        $rate = TaxRate::create([
            'jurisdiction_id' => $jurisdiction->getKey(),
            'name' => 'Component A',
            'code' => 'COMPA',
            'rate_percent' => $percent,
            'applies_to' => 'all',
            'effective_from' => now()->subYear()->toDateString(),
            'is_active' => true,
        ]);

        TaxRule::create([
            'jurisdiction_id' => $jurisdiction->getKey(),
            'condition_type' => TaxRule::ALWAYS,
            'rate_ids' => [$rate->getKey()],
            'priority' => 10,
            'is_active' => true,
        ]);

        return $rate;
    }

    private function draft(float $amount = 1000): Invoice
    {
        return $this->invoices->draft($this->user, 'INR', [
            ['description' => 'Pro plan — one month', 'unit_amount' => $amount],
        ]);
    }

    // -- issuing --------------------------------------------------------------

    public function test_issuing_freezes_the_tax_computation_onto_the_invoice(): void
    {
        $this->configureTax(18);

        $invoice = $this->invoices->issue($this->draft(1000));

        $this->assertNotNull($invoice->number);
        $this->assertEqualsWithDelta(1000.0, (float) $invoice->subtotal, 0.01);
        $this->assertEqualsWithDelta(180.0, (float) $invoice->tax_total, 0.01);
        $this->assertEqualsWithDelta(1180.0, (float) $invoice->total, 0.01);

        $line = $invoice->taxLines->first();
        $this->assertSame('Component A', $line->component_name);
        $this->assertEqualsWithDelta(18.0, (float) $line->rate_percent, 0.001);

        // Identity copied, not referenced.
        $this->assertSame('Test Business Pvt Ltd', $invoice->supplier_legal_name);
        $this->assertSame('A Customer', $invoice->customer_name);
    }

    public function test_changing_a_tax_rate_does_not_alter_an_invoice_already_issued(): void
    {
        $rate = $this->configureTax(18);
        $invoice = $this->invoices->issue($this->draft(1000));

        // The owner changes the rate. Every naive implementation silently
        // rewrites history here, and filed returns stop matching the system.
        $rate->forceFill(['rate_percent' => 28])->save();

        $reloaded = $invoice->fresh(['taxLines']);

        $this->assertEqualsWithDelta(180.0, (float) $reloaded->tax_total, 0.01);
        $this->assertEqualsWithDelta(1180.0, (float) $reloaded->total, 0.01);
        $this->assertEqualsWithDelta(18.0, (float) $reloaded->taxLines->first()->rate_percent, 0.001);
    }

    public function test_deleting_the_rate_entirely_does_not_alter_an_issued_invoice(): void
    {
        $rate = $this->configureTax(18);
        $invoice = $this->invoices->issue($this->draft(1000));

        $rate->delete();

        $reloaded = $invoice->fresh(['taxLines']);

        // The tax line is a copy with no foreign key back, so there is nothing
        // to cascade and nothing to look up.
        $this->assertCount(1, $reloaded->taxLines);
        $this->assertEqualsWithDelta(180.0, (float) $reloaded->tax_total, 0.01);
    }

    public function test_changing_the_business_address_does_not_alter_an_issued_invoice(): void
    {
        $this->configureTax();
        $invoice = $this->invoices->issue($this->draft());

        TaxSettings::current()->forceFill(['legal_name' => 'Renamed Ltd'])->save();

        $this->assertSame('Test Business Pvt Ltd', $invoice->fresh()->supplier_legal_name);
    }

    // -- immutability ---------------------------------------------------------

    public function test_an_issued_invoice_cannot_be_edited(): void
    {
        $this->configureTax();
        $invoice = $this->invoices->issue($this->draft());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('credit note');

        $invoice->forceFill(['total' => 1])->save();
    }

    public function test_an_issued_invoice_cannot_be_deleted(): void
    {
        $this->configureTax();
        $invoice = $this->invoices->issue($this->draft());

        $this->expectException(RuntimeException::class);

        $invoice->delete();
    }

    public function test_a_draft_can_still_be_changed(): void
    {
        $invoice = $this->draft();

        $invoice->forceFill(['currency' => 'USD'])->save();

        $this->assertSame('USD', $invoice->fresh()->currency);
    }

    public function test_being_paid_is_allowed_because_it_does_not_change_what_the_invoice_says(): void
    {
        $this->configureTax();
        $invoice = $this->invoices->issue($this->draft());

        $this->invoices->markPaid($invoice);

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertNotNull($invoice->fresh()->paid_at);
    }

    public function test_issuing_twice_is_refused(): void
    {
        $this->configureTax();
        $invoice = $this->invoices->issue($this->draft());

        $this->expectException(RuntimeException::class);

        $this->invoices->issue($invoice);
    }

    // -- credit notes ---------------------------------------------------------

    public function test_a_correction_produces_a_credit_note_not_an_edit(): void
    {
        $this->configureTax(18);
        $invoice = $this->invoices->issue($this->draft(1000));

        $note = $this->invoices->creditNote($invoice, 1180, 'Charged in error');

        $this->assertNotNull($note->number);
        $this->assertEqualsWithDelta(1180.0, (float) $note->amount, 0.01);
        // The tax credited with it, proportionally — keeping the tax on money
        // that was refunded would leave the business paying it.
        $this->assertEqualsWithDelta(180.0, (float) $note->tax_amount, 0.01);
        // The invoice survives, voided rather than deleted. Both documents
        // have to exist.
        $this->assertSame(Invoice::STATUS_VOID, $invoice->fresh()->status);
        $this->assertEqualsWithDelta(1180.0, (float) $invoice->fresh()->total, 0.01);
    }

    public function test_a_partial_credit_note_leaves_the_invoice_standing(): void
    {
        $this->configureTax(18);
        $invoice = $this->invoices->issue($this->draft(1000));

        $this->invoices->creditNote($invoice, 590, 'Half the month unused');

        $this->assertSame(Invoice::STATUS_ISSUED, $invoice->fresh()->status);
        $this->assertEqualsWithDelta(590.0, $invoice->fresh()->outstanding(), 0.01);
    }

    public function test_a_credit_note_cannot_exceed_what_is_left(): void
    {
        $this->configureTax();
        $invoice = $this->invoices->issue($this->draft(1000));
        $this->invoices->creditNote($invoice, 1000, 'Most of it');

        $this->expectException(RuntimeException::class);

        $this->invoices->creditNote($invoice, 500, 'And more');
    }

    public function test_a_credit_note_must_carry_a_reason(): void
    {
        $this->configureTax();
        $invoice = $this->invoices->issue($this->draft());

        $this->expectException(RuntimeException::class);

        $this->invoices->creditNote($invoice, 100, '  ');
    }

    // -- numbering ------------------------------------------------------------

    public function test_numbers_run_in_sequence_with_no_gaps(): void
    {
        $this->configureTax();

        $numbers = [];

        for ($i = 0; $i < 5; $i++) {
            $numbers[] = $this->invoices->issue($this->draft(100))->number;
        }

        $this->assertCount(5, array_unique($numbers));

        $values = array_map(fn ($n) => (int) preg_replace('/\D/', '', substr($n, -6)), $numbers);
        $this->assertSame([1, 2, 3, 4, 5], $values);
    }

    public function test_a_failed_payment_never_consumes_a_number(): void
    {
        $this->configureTax();

        // Two drafts exist; only one is issued. A system that allocated on
        // attempt would leave a gap the auditor asks about.
        $this->draft(500);
        $issued = $this->invoices->issue($this->draft(500));

        $this->assertStringEndsWith('00001', $issued->number);
    }

    public function test_the_financial_year_restarts_the_count(): void
    {
        $this->configureTax();

        $sequence = app(InvoiceNumberAllocator::class)->sequence();
        $sequence->forceFill(['reset_policy' => InvoiceNumberSequence::RESET_FINANCIAL_YEAR, 'fy_start_month' => 4])->save();

        $march = $this->invoices->issue($this->draft(100), now()->setDate(2027, 3, 30));
        $april = $this->invoices->issue($this->draft(100), now()->setDate(2027, 4, 2));

        // India's financial year starts in April — a setting, not an
        // assumption baked into code.
        $this->assertStringContainsString('2026-27', $march->number);
        $this->assertStringContainsString('2027-28', $april->number);
        $this->assertStringEndsWith('00001', $april->number);
    }

    public function test_previewing_a_number_does_not_consume_it(): void
    {
        $allocator = app(InvoiceNumberAllocator::class);

        $preview = $allocator->preview(now());
        $this->configureTax();
        $issued = $this->invoices->issue($this->draft(100));

        $this->assertSame($preview, $issued->number);
    }
}
