<?php

namespace App\Filament\Resources\Faqs\Tables;

use App\Domains\Content\Models\Faq;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class FaqsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            // Dragging beats typing sort numbers, and it is the same gesture
            // on a phone as on a desktop.
            ->reorderable('sort_order')
            ->columns([
                TextColumn::make('question')
                    ->searchable()
                    ->wrap()
                    ->limit(90),

                TextColumn::make('category')
                    ->badge()
                    ->sortable()
                    // Hidden on a phone: the question is what identifies a row,
                    // and a narrow table that keeps every column is the
                    // "squeezed desktop" Addendum A rules out.
                    ->toggleable(isToggledHiddenByDefault: false)
                    ->visibleFrom('md'),

                IconColumn::make('is_published')
                    ->label('Published')
                    ->boolean()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->options(fn () => Faq::query()
                        ->distinct()
                        ->orderBy('category')
                        ->pluck('category', 'category')
                        ->all()),

                TernaryFilter::make('is_published')->label('Published'),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ]);
    }
}
