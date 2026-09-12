<?php

namespace App\Filament\Resources\Payments\Tables;

use App\Domains\Payments\Models\Payment;
use App\Domains\Payments\Services\CheckoutService;
use App\Domains\Payments\Services\RefundService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PaymentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->dateTime('j M H:i')->label('When')->sortable(),

                TextColumn::make('user.email')->label('Customer')->searchable()->visibleFrom('md'),

                TextColumn::make('presentment_amount')
                    ->label('Amount')
                    ->state(fn (Payment $record) => $record->presentment_currency.' '.number_format((float) $record->presentment_amount, 2)),

                TextColumn::make('gateway.name')->label('Gateway')->visibleFrom('lg'),

                // Both identifiers, because support has one and the gateway
                // dashboard has the other.
                TextColumn::make('gateway_payment_id')
                    ->label('Gateway reference')
                    ->copyable()
                    ->placeholder('—')
                    ->visibleFrom('xl'),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (?string $state) => match ($state) {
                        Payment::STATUS_PAID => 'success',
                        Payment::STATUS_FAILED => 'danger',
                        Payment::STATUS_REFUNDED, Payment::STATUS_PARTIALLY_REFUNDED => 'warning',
                        default => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    Payment::STATUS_CREATED => 'Started',
                    Payment::STATUS_PENDING => 'Waiting',
                    Payment::STATUS_PAID => 'Paid',
                    Payment::STATUS_FAILED => 'Failed',
                    Payment::STATUS_REFUNDED => 'Refunded',
                    Payment::STATUS_PARTIALLY_REFUNDED => 'Partly refunded',
                ]),

                /**
                 * The reconciliation view (Addendum D §7).
                 *
                 * Anything still waiting long after it should have resolved —
                 * which is where a lost webhook shows up as a customer who
                 * paid and got nothing.
                 */
                Filter::make('needs_attention')
                    ->label('Not settled yet')
                    ->query(fn ($query) => $query
                        ->whereIn('status', [Payment::STATUS_CREATED, Payment::STATUS_PENDING])
                        ->where('created_at', '<', now()->subMinutes(30))),
            ])
            ->recordActions([
                ViewAction::make(),

                Action::make('recheck')
                    ->label('Ask the gateway')
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn (Payment $record) => ! $record->isPaid()
                        && (auth()->user()?->can('billing.manage') ?? false))
                    ->action(function (Payment $record) {
                        $settled = app(CheckoutService::class)->settle($record);

                        Notification::make()
                            ->title($settled->isPaid() ? 'Paid — the customer has been credited' : 'Still '.$settled->status)
                            ->status($settled->isPaid() ? 'success' : 'warning')
                            ->send();
                    }),

                Action::make('refund')
                    ->label('Refund')
                    ->icon('heroicon-o-receipt-refund')
                    ->color('danger')
                    ->visible(fn (Payment $record) => $record->isPaid()
                        && $record->refundable() > 0
                        && (auth()->user()?->can('billing.refund') ?? false))
                    ->schema([
                        TextInput::make('amount')
                            ->numeric()->required()->minValue(0.01)
                            ->default(fn (Payment $record) => round($record->refundable(), 2))
                            ->helperText(fn (Payment $record) => 'At most '.$record->presentment_currency.' '
                                .number_format($record->refundable(), 2).' can still be refunded.'),

                        Textarea::make('reason')->required()->rows(2)
                            ->helperText('Recorded on the refund and on the credit note, permanently.'),
                    ])
                    ->modalDescription('Unspent credits from this payment are taken back. Credits the customer has already used are not — they cost real money and cannot be recovered.')
                    ->action(function (Payment $record, array $data) {
                        try {
                            $refund = app(RefundService::class)->refund(
                                $record,
                                (float) $data['amount'],
                                $data['reason'],
                                auth()->id(),
                            );
                        } catch (\RuntimeException $e) {
                            Notification::make()->title('Not refunded')->body($e->getMessage())->danger()->persistent()->send();

                            return;
                        }

                        Notification::make()
                            ->title('Refund '.$refund->status)
                            ->body($refund->credits_revoked > 0
                                ? number_format((float) $refund->credits_revoked, 2).' unspent credits taken back.'
                                : 'No unspent credits to take back.')
                            ->success()
                            ->send();
                    }),
            ]);
    }
}
