<?php

namespace App\Filament\Resources\Invoices\Tables;

use App\Domains\Billing\Models\Invoice;
use App\Domains\Billing\Services\InvoiceService;
use App\Domains\Notifications\Services\Notifier;
use App\Domains\Notifications\Support\NotificationEvent;
use App\Domains\Security\Services\ActivityLogger;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class InvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('number')
                    ->label('Number')
                    ->searchable()
                    ->weight('bold')
                    ->placeholder('Draft'),

                TextColumn::make('user.email')
                    ->label('Customer')
                    ->searchable()
                    ->visibleFrom('md'),

                TextColumn::make('total')
                    ->formatStateUsing(fn ($state, Invoice $record) => $record->currency.' '.number_format((float) $state, 2))
                    ->label('Total'),

                TextColumn::make('tax_total')
                    ->formatStateUsing(fn ($state, Invoice $record) => $record->currency.' '.number_format((float) $state, 2))
                    ->label('Tax')
                    ->visibleFrom('lg'),

                TextColumn::make('issued_at')->dateTime('j M Y')->label('Issued')->visibleFrom('sm'),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (?string $state) => match ($state) {
                        Invoice::STATUS_PAID => 'success',
                        Invoice::STATUS_VOID => 'danger',
                        Invoice::STATUS_ISSUED => 'warning',
                        default => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    Invoice::STATUS_DRAFT => 'Draft',
                    Invoice::STATUS_ISSUED => 'Issued',
                    Invoice::STATUS_PAID => 'Paid',
                    Invoice::STATUS_VOID => 'Void',
                ]),
            ])
            ->recordActions([
                ViewAction::make(),

                /**
                 * Send the customer their invoice again.
                 *
                 * The one caller of the `invoice.issued` notification, and the
                 * reason that event exists: an ordinary purchase is already
                 * confirmed by "payment received", and a renewal by the
                 * renewal notice, so an automatic third email would be noise.
                 * What owners actually need is to RE-SEND one — the customer
                 * deleted it, the address was wrong, their accountant wants a
                 * copy.
                 *
                 * A draft has no number and is not a document yet, so there
                 * is nothing to send.
                 */
                Action::make('email_invoice')
                    ->label('Email to customer')
                    ->icon('heroicon-o-envelope')
                    ->requiresConfirmation()
                    ->modalDescription('Sends this invoice to the address on the account.')
                    ->visible(fn (Invoice $record) => $record->isIssued()
                        && $record->user !== null
                        && (auth()->user()?->can('billing.manage') ?? false))
                    ->action(function (Invoice $record) {
                        app(Notifier::class)->send($record->user, NotificationEvent::INVOICE_ISSUED, [
                            'invoice_number' => (string) $record->number,
                            'amount' => $record->currency.' '.number_format((float) $record->total, 2),
                            'issued_on' => optional($record->issued_at)->toFormattedDateString() ?: '',
                            'invoice_url' => route('billing.invoice', $record),
                        ], $record);

                        // The ACT is audited, never the message: an audit
                        // trail holding a rendered email would hold whatever
                        // the email held.
                        app(ActivityLogger::class)->log('invoice.emailed', $record, null, [
                            'number' => $record->number,
                        ]);

                        Notification::make()
                            ->title('Queued for sending')
                            ->body('It will go out with the next run of the queue.')
                            ->success()
                            ->send();
                    }),

                // The ONLY way to correct an issued invoice. There is no edit
                // action anywhere, because there is no edit.
                Action::make('credit_note')
                    ->label('Issue a credit note')
                    ->icon('heroicon-o-receipt-refund')
                    ->color('danger')
                    ->visible(fn (Invoice $record) => $record->isIssued()
                        && $record->outstanding() > 0
                        && (auth()->user()?->can('billing.refund') ?? false))
                    ->schema([
                        TextInput::make('amount')
                            ->numeric()
                            ->required()
                            ->minValue(0.01)
                            ->default(fn (Invoice $record) => round($record->outstanding(), 2))
                            ->helperText(fn (Invoice $record) => 'At most '.$record->currency.' '.number_format($record->outstanding(), 2).' is left on this invoice.'),

                        Textarea::make('reason')
                            ->required()
                            ->rows(2)
                            ->helperText('Recorded on the credit note permanently. An auditor will read this.'),
                    ])
                    ->action(function (Invoice $record, array $data) {
                        try {
                            $note = app(InvoiceService::class)->creditNote(
                                $record,
                                (float) $data['amount'],
                                $data['reason'],
                                auth()->id(),
                            );
                        } catch (\RuntimeException $e) {
                            Notification::make()->title('Not issued')->body($e->getMessage())->danger()->send();

                            return;
                        }

                        app(ActivityLogger::class)->log('invoice.credit_note_issued', $record, null, [
                            'credit_note' => $note->number,
                            'amount' => (float) $note->amount,
                            'reason' => $note->reason,
                        ]);

                        Notification::make()
                            ->title('Credit note '.$note->number.' issued')
                            ->success()
                            ->send();
                    }),
            ]);
    }
}
