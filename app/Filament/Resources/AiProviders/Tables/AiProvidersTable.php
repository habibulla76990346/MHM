<?php

namespace App\Filament\Resources\AiProviders\Tables;

use App\Domains\AI\Models\AiProvider;
use App\Domains\AI\Models\ProviderCircuitState;
use App\Domains\AI\Services\ModelSyncService;
use App\Domains\AI\Services\ProviderRegistry;
use App\Domains\Security\Services\ActivityLogger;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AiProvidersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('priority')
            ->columns([
                TextColumn::make('name')->searchable()->weight('medium'),

                // One column answering the only question that matters: is this
                // provider usable right now, and if not, why not?
                TextColumn::make('state')
                    ->label('Status')
                    ->badge()
                    ->state(fn (AiProvider $record) => $record->stateLabel())
                    ->color(fn (AiProvider $record) => match ($record->stateLabel()) {
                        'Active' => 'success',
                        'In maintenance' => 'warning',
                        default => 'danger',
                    }),

                TextColumn::make('adapter_type')->label('Type')->badge()->visibleFrom('md'),

                TextColumn::make('models_count')
                    ->label('Models')
                    ->counts('models')
                    ->visibleFrom('lg'),

                TextColumn::make('priority')->sortable()->visibleFrom('xl'),
            ])
            ->filters([
                SelectFilter::make('status')->options(AiProvider::STATUSES),
            ])
            ->recordActions([
                // §25: a real exchange with the provider, on demand.
                Action::make('test')
                    ->label('Test')
                    ->icon('heroicon-o-signal')
                    ->visible(fn () => auth()->user()?->can('providers.test'))
                    ->action(function (AiProvider $record) {
                        $adapter = app(ProviderRegistry::class)->for($record);

                        if (! $adapter) {
                            Notification::make()
                                ->title('No adapter installed')
                                ->body('This provider is set to "'.$record->adapter_type.'", which this version of Aziv AI does not have.')
                                ->danger()->send();

                            return;
                        }

                        $result = $adapter->testConnection();

                        app(ActivityLogger::class)->log('provider.tested', $record, null, [
                            // The RESULT, never the credential that produced it.
                            'success' => $result->success,
                            'latency_ms' => $result->latencyMs,
                            'error_class' => $result->errorClass,
                        ]);

                        Notification::make()
                            ->title($result->success
                                ? 'Working — answered in '.$result->latencyMs.'ms'
                                : $result->label())
                            ->body($result->success ? null : $result->detail)
                            ->status($result->success ? 'success' : 'danger')
                            ->persistent(! $result->success)
                            ->send();
                    }),

                Action::make('sync')
                    ->label('Refresh models')
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn () => auth()->user()?->can('models.sync'))
                    ->action(function (AiProvider $record) {
                        $sync = app(ModelSyncService::class);

                        if (! $sync->canSync($record)) {
                            Notification::make()
                                ->title('This provider does not publish a model list')
                                ->body('Add its models by hand in Admin → AI → Models. That is expected for some providers, not a fault.')
                                ->warning()->send();

                            return;
                        }

                        $log = $sync->sync($record, auth()->id());

                        app(ActivityLogger::class)->log('provider.models_synced', $record, null, [
                            'status' => $log->status,
                            'added' => $log->models_added,
                            'deprecated' => $log->models_deprecated,
                        ]);

                        Notification::make()
                            ->title($log->status === 'success' ? 'Catalog refreshed' : 'Refresh failed')
                            ->body($log->status === 'success'
                                ? $log->summary().'. New models arrive switched off until you enable them.'
                                : $log->error_message)
                            ->status($log->status === 'success' ? 'success' : 'danger')
                            ->persistent($log->status !== 'success')
                            ->send();
                    }),

                Action::make('reset_circuit')
                    ->label('Reset circuit breaker')
                    ->icon('heroicon-o-bolt')
                    ->requiresConfirmation()
                    ->visible(fn (AiProvider $record) => auth()->user()?->can('providers.manage')
                        && $record->circuit?->isOpen())
                    ->action(function (AiProvider $record) {
                        $before = $record->circuit?->only(['state', 'failure_count', 'forced_open']);

                        ProviderCircuitState::updateOrCreate(
                            ['provider_id' => $record->getKey()],
                            ['state' => ProviderCircuitState::CLOSED, 'failure_count' => 0, 'forced_open' => false, 'opened_at' => null],
                        );

                        app(ActivityLogger::class)->log('provider.circuit_reset', $record, $before, ['state' => 'closed']);

                        Notification::make()->title('Back in rotation')->success()->send();
                    }),

                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ]);
    }
}
