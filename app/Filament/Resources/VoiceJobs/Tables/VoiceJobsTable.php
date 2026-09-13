<?php

namespace App\Filament\Resources\VoiceJobs\Tables;

use App\Domains\Voice\Models\VoiceJob;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class VoiceJobsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->dateTime('j M Y H:i')->label('When')->sortable(),

                TextColumn::make('owner.email')->label('Customer')->searchable()->visibleFrom('md'),

                TextColumn::make('kind')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => $state === VoiceJob::TRANSCRIPTION
                        ? 'Heard'
                        : 'Spoken'),

                // Seconds, not the transcript. What was said is a
                // conversation, not something an administrator reviews.
                TextColumn::make('seconds')
                    ->label('Audio (s)')
                    ->numeric(decimalPlaces: 1)
                    ->summarize(Sum::make()->numeric(decimalPlaces: 0)),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (?string $state) => match ($state) {
                        VoiceJob::COMPLETED => 'success',
                        VoiceJob::FAILED => 'danger',
                        default => 'warning',
                    })
                    // Scrubbed before it was stored.
                    ->description(fn (VoiceJob $record) => $record->failure_reason),

                TextColumn::make('model.display_name')->label('Model')->placeholder('—')->visibleFrom('lg'),

                TextColumn::make('credit_cost')
                    ->label('Credits')
                    ->numeric(decimalPlaces: 2)
                    ->summarize(Sum::make()->numeric(decimalPlaces: 2))
                    ->visibleFrom('md'),
            ])
            ->filters([
                SelectFilter::make('kind')->options([
                    VoiceJob::TRANSCRIPTION => 'Heard',
                    VoiceJob::SPEECH => 'Spoken',
                ]),
                SelectFilter::make('status')->options([
                    VoiceJob::QUEUED => 'Queued',
                    VoiceJob::WORKING => 'Working',
                    VoiceJob::COMPLETED => 'Completed',
                    VoiceJob::FAILED => 'Failed',
                ]),
            ]);
    }
}
