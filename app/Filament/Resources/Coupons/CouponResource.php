<?php

namespace App\Filament\Resources\Coupons;

use App\Domains\Billing\Models\Coupon;
use App\Filament\Resources\Coupons\Pages\CreateCoupon;
use App\Filament\Resources\Coupons\Pages\EditCoupon;
use App\Filament\Resources\Coupons\Pages\ListCoupons;
use App\Filament\Resources\Coupons\Schemas\CouponForm;
use App\Filament\Resources\Coupons\Tables\CouponsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/** ADMIN → Billing → Coupons (§20). */
class CouponResource extends Resource
{
    protected static ?string $model = Coupon::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static ?string $navigationLabel = 'Coupons';

    protected static string|\UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'code';

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('billing.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('billing.manage') ?? false;
    }

    public static function canEdit($record): bool
    {
        return auth()->user()?->can('billing.manage') ?? false;
    }

    /** A redeemed coupon is history. Deleting it would orphan the redemptions. */
    public static function canDelete($record): bool
    {
        return (auth()->user()?->can('billing.manage') ?? false) && $record->redeemed_count === 0;
    }

    public static function form(Schema $schema): Schema
    {
        return CouponForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CouponsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCoupons::route('/'),
            'create' => CreateCoupon::route('/create'),
            'edit' => EditCoupon::route('/{record}/edit'),
        ];
    }
}
