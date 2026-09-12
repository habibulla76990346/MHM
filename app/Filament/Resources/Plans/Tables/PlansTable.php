<?php

namespace App\Filament\Resources\Plans\Tables;

use App\Domains\Billing\Models\Plan;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PlansTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                TextColumn::make('name')->searchable()->weight('bold'),

                TextColumn::make('billing_cycle')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => Plan::CYCLES[$state] ?? $state)
                    ->visibleFrom('md'),

                // ->state(), not ->formatStateUsing() on a relationship name:
                // a HasMany column resolves to a collection Filament renders as
                // nothing, and an EMPTY CELL is not just an ugly gap — it is a
                // 32px-tall link that fails the touch-target gate. Found by the
                // gate the first time this table had rows in it.
                TextColumn::make('price_summary')
                    ->label('Price')
                    ->state(fn (Plan $record) => $record->prices
                        ->map(fn ($p) => $p->currency.' '.rtrim(rtrim(number_format((float) $p->amount, 2), '0'), '.'))
                        ->implode(' · ') ?: 'Not priced')
                    ->visibleFrom('sm'),

                TextColumn::make('credits_per_period')
                    ->label('Credits')
                    ->numeric()
                    ->visibleFrom('lg'),

                TextColumn::make('subscriptions_count')
                    ->counts('subscriptions')
                    ->label('Customers')
                    ->visibleFrom('md'),

                IconColumn::make('is_default')
                    ->label('Default')
                    ->boolean()
                    ->visibleFrom('lg'),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (?string $state) => match ($state) {
                        Plan::STATUS_ACTIVE => 'success',
                        Plan::STATUS_ARCHIVED => 'gray',
                        default => 'warning',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    Plan::STATUS_DRAFT => 'Draft',
                    Plan::STATUS_ACTIVE => 'Active',
                    Plan::STATUS_ARCHIVED => 'Archived',
                ]),
            ])
            ->recordActions([EditAction::make()]);
    }
}
