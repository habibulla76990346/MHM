<?php

namespace App\Filament\Resources\Payments;

use App\Domains\Payments\Models\Payment;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Filament\Resources\Payments\Pages\ViewPayment;
use App\Filament\Resources\Payments\Tables\PaymentsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * ADMIN → Billing → Payments (Addendum D §7).
 *
 * Read-only, with two actions that matter: re-query the gateway, and refund.
 * There is no edit form, because a payment record is a statement about
 * something that happened at a third party — editing it would only make the
 * two disagree, which is the exact problem the reconciliation screen exists to
 * surface.
 */
class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'Payments';

    protected static string|\UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 35;

    protected static ?string $recordTitleAttribute = 'uuid';

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
        return PaymentsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPayments::route('/'),
            'view' => ViewPayment::route('/{record}'),
        ];
    }
}
