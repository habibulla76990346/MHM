<?php

namespace App\Filament\Resources\Coupons\Tables;

use App\Domains\Billing\Models\Coupon;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CouponsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('code')->searchable()->weight('bold')->copyable(),

                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        Coupon::PERCENTAGE => 'Percentage',
                        Coupon::FIXED => 'Fixed',
                        default => 'Credits',
                    })
                    ->visibleFrom('sm'),

                TextColumn::make('value')->numeric()->visibleFrom('sm'),

                TextColumn::make('redeemed_count')
                    ->label('Used')
                    ->formatStateUsing(fn ($state, Coupon $record) => $record->max_redemptions
                        ? $state.' of '.$record->max_redemptions
                        : (string) $state)
                    ->visibleFrom('md'),

                TextColumn::make('valid_until')->dateTime('j M Y')->label('Until')->visibleFrom('lg'),

                IconColumn::make('is_active')->boolean()->label('Active'),
            ])
            ->recordActions([EditAction::make()]);
    }
}
