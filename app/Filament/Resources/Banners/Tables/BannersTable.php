<?php

namespace App\Filament\Resources\Banners\Tables;

use App\Domains\Content\Models\Banner;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class BannersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('priority', 'desc')
            ->columns([
                TextColumn::make('title')->searchable()->wrap()->limit(70),

                // The one thing an administrator actually wants to know, and
                // the one thing dates alone do not answer: is it showing?
                TextColumn::make('state')
                    ->label('Status')
                    ->badge()
                    ->state(fn (Banner $record) => $record->stateLabel())
                    ->color(fn (Banner $record) => $record->isLive() ? 'success' : 'gray'),

                TextColumn::make('audience')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Banner::AUDIENCES[$state] ?? $state)
                    ->visibleFrom('md'),

                TextColumn::make('priority')->sortable()->visibleFrom('lg'),
            ])
            ->filters([
                SelectFilter::make('audience')->options(Banner::AUDIENCES),
                SelectFilter::make('variant')->label('Tone')->options(Banner::VARIANTS),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ]);
    }
}
