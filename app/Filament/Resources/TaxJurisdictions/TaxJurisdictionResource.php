<?php

namespace App\Filament\Resources\TaxJurisdictions;

use App\Domains\Tax\Models\TaxJurisdiction;
use App\Filament\Resources\TaxJurisdictions\Pages\CreateTaxJurisdiction;
use App\Filament\Resources\TaxJurisdictions\Pages\EditTaxJurisdiction;
use App\Filament\Resources\TaxJurisdictions\Pages\ListTaxJurisdictions;
use App\Filament\Resources\TaxJurisdictions\Schemas\TaxJurisdictionForm;
use App\Filament\Resources\TaxJurisdictions\Tables\TaxJurisdictionsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * ADMIN → Billing → Tax rules (Addendum F).
 *
 * A jurisdiction owns its rates and its rules, so they are edited together on
 * one screen rather than as three lists an administrator has to cross-
 * reference. Everything on it is a value THEY choose and name — there is no
 * suggested rate, no pre-filled label and no default treatment anywhere.
 */
class TaxJurisdictionResource extends Resource
{
    protected static ?string $model = TaxJurisdiction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?string $navigationLabel = 'Tax rules';

    protected static string|\UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 50;

    protected static ?string $recordTitleAttribute = 'name';

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('billing.tax.manage') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('billing.tax.manage') ?? false;
    }

    public static function canEdit($record): bool
    {
        return auth()->user()?->can('billing.tax.manage') ?? false;
    }

    /**
     * A jurisdiction whose rates appear on an issued invoice may still be
     * deleted — the invoice keeps its own copies and does not reference it.
     * That is the whole point of the snapshot.
     */
    public static function canDelete($record): bool
    {
        return auth()->user()?->can('billing.tax.manage') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return TaxJurisdictionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TaxJurisdictionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTaxJurisdictions::route('/'),
            'create' => CreateTaxJurisdiction::route('/create'),
            'edit' => EditTaxJurisdiction::route('/{record}/edit'),
        ];
    }
}
