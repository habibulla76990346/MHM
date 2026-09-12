<?php

namespace App\Filament\Resources\Announcements\Tables;

use App\Domains\Notifications\Models\Announcement;
use App\Domains\Notifications\Services\AnnouncementService;
use App\Domains\Security\Services\ActivityLogger;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AnnouncementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('title')->searchable()->weight('medium')->wrap(),

                TextColumn::make('audience')
                    ->formatStateUsing(fn (?string $state) => Announcement::AUDIENCES[$state] ?? $state)
                    ->wrap()
                    ->visibleFrom('md'),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => Announcement::STATUSES[$state] ?? $state)
                    ->color(fn (?string $state) => match ($state) {
                        Announcement::STATUS_SENT => 'success',
                        Announcement::STATUS_SCHEDULED => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('send_email')
                    ->label('Email')
                    ->state(fn (Announcement $record) => $record->send_email ? 'Yes' : 'In the app only')
                    ->visibleFrom('lg'),

                TextColumn::make('sent_at')
                    ->label('Sent')
                    ->dateTime('j M Y H:i')
                    ->placeholder('Not yet')
                    ->description(fn (Announcement $record) => $record->isSent()
                        ? trans_choice('{0}reached nobody|{1}reached 1 person|[2,*]reached :count people',
                            (int) $record->recipient_count, ['count' => (int) $record->recipient_count])
                        : null)
                    ->visibleFrom('sm'),
            ])
            ->filters([
                SelectFilter::make('status')->options(Announcement::STATUSES),
            ])
            ->recordActions([
                /**
                 * Send it now, whatever the schedule said.
                 *
                 * Confirmed, and the confirmation names the number of people:
                 * "send to 4,812 customers" is a different decision from
                 * "send", and an owner should be told which one they are
                 * making. The service refuses a second send on its own.
                 */
                Action::make('send_now')
                    ->label('Send now')
                    ->icon('heroicon-o-paper-airplane')
                    ->requiresConfirmation()
                    ->modalDescription(fn (Announcement $record) => 'This reaches '
                        .app(AnnouncementService::class)->audienceSize($record).' customer(s)'
                        .($record->send_email ? ' in the app and by email.' : ' in the app.')
                        .' It cannot be taken back.')
                    ->visible(fn (Announcement $record) => ! $record->isSent()
                        && (auth()->user()?->can('announcements.broadcast') ?? false))
                    ->action(function (Announcement $record) {
                        $count = app(AnnouncementService::class)->send($record);

                        app(ActivityLogger::class)->log('announcement.sent', $record, null, [
                            'audience' => $record->audience,
                            'by_email' => $record->send_email,
                            'recipients' => $count,
                        ]);

                        Notification::make()
                            ->title('Sent to '.$count.' customer(s)')
                            ->body($record->send_email
                                ? 'Emails are queued and go out with the next run of the queue.'
                                : 'It is in the app for everyone in the audience.')
                            ->success()
                            ->send();
                    }),

                EditAction::make()->visible(fn (Announcement $record) => ! $record->isSent()),
                DeleteAction::make()->visible(fn (Announcement $record) => ! $record->isSent()),
            ]);
    }
}
