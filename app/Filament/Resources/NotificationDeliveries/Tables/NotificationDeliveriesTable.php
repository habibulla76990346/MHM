<?php

namespace App\Filament\Resources\NotificationDeliveries\Tables;

use App\Domains\Notifications\Models\NotificationDelivery;
use App\Domains\Notifications\Support\NotificationEvent;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class NotificationDeliveriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->dateTime('j M Y H:i')->label('When'),

                TextColumn::make('event_key')
                    ->label('Message')
                    ->formatStateUsing(fn (?string $state) => NotificationEvent::label((string) $state))
                    ->wrap(),

                TextColumn::make('user.email')->label('To')->searchable()->visibleFrom('md'),

                TextColumn::make('channel')
                    ->formatStateUsing(fn (?string $state) => NotificationEvent::CHANNELS[$state] ?? $state)
                    ->badge()
                    ->visibleFrom('sm'),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => NotificationDelivery::STATUSES[$state] ?? $state)
                    ->color(fn (?string $state) => match ($state) {
                        NotificationDelivery::STATUS_SENT => 'success',
                        NotificationDelivery::STATUS_FAILED => 'danger',
                        NotificationDelivery::STATUS_SUPPRESSED => 'gray',
                        default => 'warning',
                    })
                    // Scrubbed before it was stored, so this cannot show a
                    // mail server's password back to whoever reads the log.
                    ->description(fn (NotificationDelivery $record) => $record->error),

                TextColumn::make('reference_id')
                    ->label('About')
                    ->state(fn (NotificationDelivery $record) => $record->reference_type
                        ? $record->reference_type.' #'.$record->reference_id
                        : null)
                    ->placeholder('—')
                    ->visibleFrom('lg'),
            ])
            ->filters([
                SelectFilter::make('status')->options(NotificationDelivery::STATUSES),
                SelectFilter::make('event_key')->label('Message')->options(fn () => NotificationEvent::options()),
            ]);
    }
}
