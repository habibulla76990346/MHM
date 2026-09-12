<?php

namespace App\Filament\Resources\NotificationTemplates\Tables;

use App\Domains\Notifications\Models\NotificationTemplate;
use App\Domains\Notifications\Support\NotificationEvent;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class NotificationTemplatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('event_key')
            ->columns([
                TextColumn::make('event_key')
                    ->label('Message')
                    ->formatStateUsing(fn (?string $state) => NotificationEvent::label((string) $state))
                    ->description(fn (NotificationTemplate $record) => NotificationEvent::definition((string) $record->event_key)['group'] ?? null)
                    ->searchable()
                    ->weight('medium'),

                TextColumn::make('channel')
                    ->formatStateUsing(fn (?string $state) => NotificationEvent::CHANNELS[$state] ?? $state)
                    ->badge(),

                TextColumn::make('subject')
                    ->placeholder('As shipped')
                    ->wrap()
                    ->visibleFrom('md'),

                TextColumn::make('is_active')
                    ->label('Status')
                    ->badge()
                    ->state(fn (NotificationTemplate $record) => $record->is_active ? 'Sending' : 'Switched off')
                    ->color(fn (NotificationTemplate $record) => $record->is_active ? 'success' : 'gray'),
            ])
            ->emptyStateHeading('No overrides yet')
            ->emptyStateDescription(
                'Every message already has wording that ships with Aziv AI. Add a template only to change one.'
            )
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->modalDescription('The message keeps sending — it goes back to the wording Aziv AI ships with. To stop it entirely, edit it and switch it off instead.'),
            ]);
    }
}
