<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Resources\Invoices\InvoiceResource;
use Filament\Resources\Pages\Page;

/**
 * One invoice, exactly as it was issued.
 *
 * Rendered from the SNAPSHOT on the row — the supplier and customer details,
 * the tax component names and rates frozen at issue — never from today's
 * settings. That is what makes reopening a two-year-old invoice show the
 * two-year-old document.
 */
class ViewInvoice extends Page
{
    protected static string $resource = InvoiceResource::class;

    protected string $view = 'filament.pages.invoice';

    public $record;

    public function mount(int|string $record): void
    {
        $this->record = static::getResource()::resolveRecordRouteBinding($record);

        abort_unless($this->record !== null, 404);
        abort_unless(auth()->user()?->can('billing.view') ?? false, 403);

        $this->record->load(['lines', 'taxLines', 'creditNotes', 'user']);
    }

    public function getTitle(): string
    {
        return $this->record->number ?? 'Draft invoice';
    }
}
