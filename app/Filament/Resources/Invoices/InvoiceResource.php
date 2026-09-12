<?php

namespace App\Filament\Resources\Invoices;

use App\Domains\Billing\Models\Invoice;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Filament\Resources\Invoices\Tables\InvoicesTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * ADMIN → Billing → Invoices (§20, Addendum F).
 *
 * DELIBERATELY WITHOUT AN EDIT SCREEN. An issued invoice cannot be changed —
 * the model layer refuses it — so offering a form that would always fail would
 * be a lie told by the interface. Corrections are made with the credit note
 * action, which is what accounting requires anyway.
 */
class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'Invoices';

    protected static string|\UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'number';

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('billing.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return InvoicesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInvoices::route('/'),
            'view' => ViewInvoice::route('/{record}'),
        ];
    }
}
