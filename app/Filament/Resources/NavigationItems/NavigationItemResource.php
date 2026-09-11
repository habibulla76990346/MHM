<?php

namespace App\Filament\Resources\NavigationItems;

use App\Domains\Content\Models\NavigationItem;
use App\Domains\Content\Models\NavigationMenu;
use App\Filament\Resources\NavigationItems\Pages\CreateNavigationItem;
use App\Filament\Resources\NavigationItems\Pages\EditNavigationItem;
use App\Filament\Resources\NavigationItems\Pages\ListNavigationItems;
use App\Filament\Resources\NavigationItems\Schemas\NavigationItemForm;
use App\Filament\Resources\NavigationItems\Tables\NavigationItemsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * ADMIN → Content → Navigation (decision D-11).
 *
 * The owner's requirement was that the Admin Panel control "navigation labels,
 * icons, ordering, visibility and destination where technically appropriate".
 * Each of those is a column here, so none of them needs a developer.
 *
 * The bottom bar refuses a fifth item. That is not a preference: at 320px a
 * fifth tab drops every target below the 44px minimum, and the responsive gate
 * would fail the build. Better to refuse in the editor and say why.
 */
class NavigationItemResource extends Resource
{
    protected static ?string $model = NavigationItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBars3;

    protected static ?string $navigationLabel = 'Navigation';

    protected static string|\UnitEnum|null $navigationGroup = 'Content';

    protected static ?int $navigationSort = 40;

    protected static ?string $recordTitleAttribute = 'label';

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('content.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('content.manage') ?? false;
    }

    public static function canEdit($record): bool
    {
        return auth()->user()?->can('content.manage') ?? false;
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->can('content.manage') ?? false;
    }

    /** @return array<int, string> menus that still have room for another item */
    public static function menuOptions(?NavigationItem $ignore = null): array
    {
        return NavigationMenu::all()
            ->mapWithKeys(function (NavigationMenu $menu) use ($ignore) {
                $label = $menu->name;

                if ($menu->max_items) {
                    $used = $menu->items()->count();
                    $label .= " ({$used} of {$menu->max_items})";

                    if ($used >= $menu->max_items && $ignore?->menu_id !== $menu->getKey()) {
                        $label .= ' — full';
                    }
                }

                return [$menu->getKey() => $label];
            })
            ->all();
    }

    public static function form(Schema $schema): Schema
    {
        return NavigationItemForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return NavigationItemsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNavigationItems::route('/'),
            'create' => CreateNavigationItem::route('/create'),
            'edit' => EditNavigationItem::route('/{record}/edit'),
        ];
    }
}
