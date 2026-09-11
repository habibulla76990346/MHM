<?php

namespace App\Filament\Resources\FeatureFlags\Tables;

use App\Domains\Content\Models\FeatureFlag;
use App\Domains\Content\Services\FeatureFlagService;
use App\Domains\Security\Services\ActivityLogger;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class FeatureFlagsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->searchable()->weight('medium'),

                TextColumn::make('key')->color('gray')->visibleFrom('md')->searchable(),

                // Toggled straight from the list: switching a feature on is the
                // whole interaction, and making it a two-page trip helps nobody.
                ToggleColumn::make('is_enabled')
                    ->label('On')
                    ->beforeStateUpdated(function (FeatureFlag $record, bool $state) {
                        // Rule 8 applies to a toggle exactly as it does to a
                        // form: authorise, validate, audit with before/after.
                        app(ActivityLogger::class)->log(
                            'feature_flag.toggled',
                            $record,
                            ['is_enabled' => $record->is_enabled],
                            ['is_enabled' => $state],
                        );
                    })
                    ->afterStateUpdated(fn () => app(FeatureFlagService::class)->flush())
                    ->disabled(fn () => ! auth()->user()?->can('settings.manage')),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ]);
    }
}
