<?php

namespace App\Filament\Resources\Pages\Tables;

use App\Domains\Content\Models\Page;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('title')->searchable()->weight('medium'),

                // Draft, scheduled and live are different things, and "status"
                // alone cannot tell them apart once scheduling exists.
                TextColumn::make('state')
                    ->label('Status')
                    ->badge()
                    ->state(fn (Page $record) => match (true) {
                        $record->isLive() => 'Live',
                        $record->isScheduled() => 'Scheduled '.$record->published_at->diffForHumans(),
                        default => 'Draft',
                    })
                    ->color(fn (Page $record) => match (true) {
                        $record->isLive() => 'success',
                        $record->isScheduled() => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('slug')->visibleFrom('md')->color('gray'),

                IconColumn::make('show_in_footer')->label('Footer')->boolean()->visibleFrom('lg'),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    Page::STATUS_DRAFT => 'Draft',
                    Page::STATUS_PUBLISHED => 'Published',
                ]),
            ])
            ->recordActions([
                Action::make('view')
                    ->label('View')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (Page $record) => url('/p/'.$record->slug))
                    ->openUrlInNewTab()
                    // A draft has no public address to open.
                    ->visible(fn (Page $record) => $record->isLive()),

                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ]);
    }
}
