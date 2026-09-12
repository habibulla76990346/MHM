<?php

namespace App\Filament\Resources\TaxJurisdictions\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TaxJurisdictionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('priority')
            ->columns([
                TextColumn::make('name')->searchable()->weight('bold'),
                TextColumn::make('country')->badge()->visibleFrom('sm'),
                TextColumn::make('state')->placeholder('Whole country')->visibleFrom('md'),
                TextColumn::make('rates_count')->counts('rates')->label('Rates')->visibleFrom('md'),
                TextColumn::make('rules_count')->counts('rules')->label('Rules')->visibleFrom('lg'),
                IconColumn::make('is_active')->boolean()->label('Active'),
            ])
            ->recordActions([EditAction::make()]);
    }
}
