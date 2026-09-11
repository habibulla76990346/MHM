<?php

namespace App\Filament\Resources\AiModels\Tables;

use App\Domains\AI\Models\AiModel;
use App\Domains\AI\Models\AiProvider;
use App\Domains\AI\Support\Capability;
use App\Domains\Security\Services\ActivityLogger;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class AiModelsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('display_name')->label('Model')->searchable()->weight('medium'),

                TextColumn::make('provider.name')->badge()->visibleFrom('md')->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => AiModel::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        AiModel::STATUS_STABLE => 'success',
                        AiModel::STATUS_PREVIEW, AiModel::STATUS_EXPERIMENTAL => 'warning',
                        default => 'gray',
                    }),

                // Switched straight from the list: after a catalog refresh an
                // owner is deciding which of several new models to turn on, and
                // a two-page trip each time helps nobody.
                ToggleColumn::make('is_enabled')
                    ->label('On')
                    ->beforeStateUpdated(fn (AiModel $record, bool $state) => app(ActivityLogger::class)->log(
                        'model.toggled',
                        $record,
                        ['is_enabled' => $record->is_enabled],
                        ['is_enabled' => $state],
                    ))
                    ->disabled(fn () => ! auth()->user()?->can('models.manage')),

                TextColumn::make('context_window')
                    ->label('Context')
                    ->formatStateUsing(fn (?int $state) => $state ? number_format($state) : '—')
                    ->visibleFrom('lg'),

                TextColumn::make('source')
                    ->badge()
                    ->color('gray')
                    ->visibleFrom('xl'),
            ])
            ->filters([
                SelectFilter::make('provider_id')
                    ->label('Provider')
                    ->options(fn () => AiProvider::orderBy('name')->pluck('name', 'id')->all()),

                SelectFilter::make('status')->options(AiModel::STATUSES),

                TernaryFilter::make('is_enabled')->label('Available to customers'),

                SelectFilter::make('capability')
                    ->label('Can do')
                    ->options(collect(Capability::all())->map(fn ($c) => $c['label'])->all())
                    ->query(fn ($query, array $data) => filled($data['value'] ?? null)
                        ? $query->whereHas('capabilities', fn ($q) => $q
                            ->where('capability', $data['value'])
                            ->where('is_supported', true))
                        : $query),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ]);
    }
}
