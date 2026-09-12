<?php

namespace App\Filament\Resources\PaymentGateways;

use App\Domains\Payments\Models\PaymentGatewayRecord;
use App\Filament\Resources\PaymentGateways\Pages\EditPaymentGateway;
use App\Filament\Resources\PaymentGateways\Pages\ListPaymentGateways;
use App\Filament\Resources\PaymentGateways\Schemas\PaymentGatewayForm;
use App\Filament\Resources\PaymentGateways\Tables\PaymentGatewaysTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * ADMIN → Billing → Payment gateways (Addendum D §7).
 *
 * The thirteen controls the owner listed live here: enable and disable, set a
 * default, enter credentials securely, test the connection, order by priority,
 * restrict by country and currency, route payment types, switch sandbox and
 * live, and add a future gateway without touching anything else.
 *
 * Creating a gateway from scratch is deliberately NOT offered: a gateway
 * without an adapter cannot take money, and a form that lets someone invent
 * one would only produce a row that silently never works. Adapters register
 * themselves, and their rows are seeded.
 */
class PaymentGatewayResource extends Resource
{
    protected static ?string $model = PaymentGatewayRecord::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static ?string $navigationLabel = 'Payment gateways';

    protected static string|\UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 70;

    protected static ?string $recordTitleAttribute = 'name';

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('billing.gateways.manage') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return auth()->user()?->can('billing.gateways.manage') ?? false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return PaymentGatewayForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PaymentGatewaysTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPaymentGateways::route('/'),
            'edit' => EditPaymentGateway::route('/{record}/edit'),
        ];
    }
}
