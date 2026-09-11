<?php

namespace App\Filament\Resources\NavigationItems\Tables;

use App\Domains\Content\Models\NavigationItem;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class NavigationItemsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultGroup('menu.name')
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                TextColumn::make('label')->searchable()->weight('medium'),

                // Whether the link actually resolves, not merely what it was
                // configured to point at. A renamed route or an unpublished
                // page is the difference between a link and a dead end.
                TextColumn::make('destination')
                    ->label('Goes to')
                    ->state(fn (NavigationItem $record) => $record->href() ?? 'Not reachable')
                    ->color(fn (NavigationItem $record) => $record->href() ? 'gray' : 'danger')
                    ->wrap(),

                IconColumn::make('is_visible')->label('Visible')->boolean(),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ]);
    }
}
