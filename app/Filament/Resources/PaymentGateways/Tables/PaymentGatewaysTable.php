<?php

namespace App\Filament\Resources\PaymentGateways\Tables;

use App\Domains\Payments\Models\PaymentGatewayRecord;
use App\Domains\Payments\Services\PaymentGatewayRegistry;
use App\Domains\Payments\Services\WebhookProcessor;
use App\Domains\Security\Services\ActivityLogger;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class PaymentGatewaysTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('priority')
            ->reorderable('priority')
            ->columns([
                TextColumn::make('name')->searchable()->weight('bold'),

                TextColumn::make('state')
                    ->label('State')
                    ->state(fn (PaymentGatewayRecord $record) => $record->stateLabel())
                    ->badge()
                    ->color(fn (string $state) => match (true) {
                        str_starts_with($state, 'Live') => 'success',
                        str_starts_with($state, 'Sandbox') => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('checkout_mode')
                    ->label('Checkout')
                    ->state(fn (PaymentGatewayRecord $record) => $record->checkoutModeLabel())
                    ->visibleFrom('lg'),

                TextColumn::make('payments_count')
                    ->counts('payments')
                    ->label('Payments')
                    ->visibleFrom('md'),

                IconColumn::make('is_default')->boolean()->label('Default')->visibleFrom('md'),
            ])
            ->recordActions([
                EditAction::make(),

                /**
                 * A live, authenticated call the owner asked for.
                 *
                 * Deliberately a READ: testing must never create a real order
                 * or take a payment. It reports a class and a latency and
                 * NEVER the credential or the gateway's own error text.
                 */
                Action::make('test')
                    ->label('Test connection')
                    ->icon('heroicon-o-signal')
                    ->visible(fn () => auth()->user()?->can('billing.gateways.manage') ?? false)
                    ->action(function (PaymentGatewayRecord $record) {
                        $adapter = app(PaymentGatewayRegistry::class)->for($record);

                        if (! $adapter) {
                            Notification::make()->title('No adapter is installed for this gateway')->danger()->send();

                            return;
                        }

                        $result = $adapter->testConnection();

                        if ($result->ok) {
                            $record->activeCredential()?->forceFill([
                                'last_verified_at' => now(),
                                'verified_by' => auth()->id(),
                            ])->save();
                        }

                        app(ActivityLogger::class)->log('payments.gateway_tested', $record, null, [
                            'ok' => $result->ok,
                            'latency_ms' => $result->latencyMs,
                            'error_class' => $result->errorClass,
                        ]);

                        Notification::make()
                            ->title($result->ok ? 'Connected in '.$result->latencyMs.' ms' : 'Could not connect')
                            ->body($result->ok ? null : $result->advice)
                            ->status($result->ok ? 'success' : 'danger')
                            ->persistent(! $result->ok)
                            ->send();
                    }),

                /**
                 * The webhook details an owner pastes into the gateway's own
                 * dashboard, plus what has actually arrived.
                 */
                Action::make('webhook')
                    ->label('Webhook')
                    ->icon('heroicon-o-bolt')
                    ->modalSubmitAction(false)
                    ->modalDescription(fn (PaymentGatewayRecord $record) => $record->webhookUrl())
                    ->modalContent(function (PaymentGatewayRecord $record) {
                        $last = $record->webhookEvents()->latest('received_at')->first();
                        $rejected = app(WebhookProcessor::class)->recentRejections($record, 60 * 24);

                        return new HtmlString(view('filament.partials.webhook-status', [
                            'gateway' => $record,
                            'last' => $last,
                            'rejected' => $rejected,
                            'hasSecret' => filled($record->activeCredential()?->signingSecret()),
                        ])->render());
                    }),

                /**
                 * Switching to live is its own action with its own
                 * confirmation, because doing it by accident means either
                 * taking real money in a test or failing to take it in
                 * production.
                 */
                Action::make('switch_mode')
                    ->label(fn (PaymentGatewayRecord $record) => $record->isLive() ? 'Switch to sandbox' : 'Switch to live')
                    ->icon('heroicon-o-arrows-right-left')
                    ->color(fn (PaymentGatewayRecord $record) => $record->isLive() ? 'gray' : 'danger')
                    ->requiresConfirmation()
                    ->modalDescription(fn (PaymentGatewayRecord $record) => $record->isLive()
                        ? 'Payments will stop being real. Test payments only.'
                        : 'REAL MONEY. Every payment from now on is a real charge to a real customer.')
                    ->visible(fn () => auth()->user()?->can('billing.gateways.manage') ?? false)
                    ->action(function (PaymentGatewayRecord $record) {
                        $target = $record->isLive()
                            ? PaymentGatewayRecord::MODE_SANDBOX
                            : PaymentGatewayRecord::MODE_LIVE;

                        $hasCredentials = $record->credentials()
                            ->where('mode', $target)
                            ->where('status', 'active')
                            ->exists();

                        if (! $hasCredentials) {
                            Notification::make()
                                ->title('No '.$target.' credentials')
                                ->body('Add credentials for '.$target.' mode before switching, or the gateway will fail on the first payment.')
                                ->warning()
                                ->send();

                            return;
                        }

                        $before = ['mode' => $record->mode];
                        $record->forceFill(['mode' => $target, 'updated_by' => auth()->id()])->save();

                        app(ActivityLogger::class)->log('payments.gateway_mode_changed', $record, $before, ['mode' => $target]);

                        Notification::make()->title('Now in '.$target.' mode')->success()->send();
                    }),
            ]);
    }
}
