<?php

namespace App\Filament\Resources\Plans;

use App\Domains\Billing\Models\Plan;
use App\Filament\Resources\Plans\Pages\CreatePlan;
use App\Filament\Resources\Plans\Pages\EditPlan;
use App\Filament\Resources\Plans\Pages\ListPlans;
use App\Filament\Resources\Plans\Schemas\PlanForm;
use App\Filament\Resources\Plans\Tables\PlansTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * ADMIN → Billing → Plans (§19).
 *
 * Every dimension the blueprint lists as configurable is here: price and
 * currency, billing cycle, credits, message and file limits, storage, which
 * models and providers a plan may reach, and the features it unlocks. None of
 * them is a constant in code, which is what "fully configurable" has to mean
 * if renaming a plan is not to break the product.
 */
class PlanResource extends Resource
{
    protected static ?string $model = Plan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Plans';

    protected static string|\UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

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

    /**
     * A plan somebody is subscribed to is never deletable.
     *
     * Deleting it would leave live subscriptions pointing at nothing, and the
     * customers on it with no entitlements and no explanation. Archiving is
     * the operation an owner actually wants.
     */
    public static function canDelete($record): bool
    {
        return (auth()->user()?->can('billing.manage') ?? false)
            && ! $record->subscriptions()->exists();
    }

    public static function form(Schema $schema): Schema
    {
        return PlanForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PlansTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlans::route('/'),
            'create' => CreatePlan::route('/create'),
            'edit' => EditPlan::route('/{record}/edit'),
        ];
    }
}
