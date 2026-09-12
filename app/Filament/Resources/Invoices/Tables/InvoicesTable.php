<?php

namespace App\Filament\Resources\Invoices\Tables;

use App\Domains\Billing\Models\Invoice;
use App\Domains\Billing\Services\InvoiceService;
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
