<?php

namespace App\Filament\Resources\ImageGenerations\Tables;

use App\Domains\Files\Services\FileStorage;
use App\Domains\Images\Models\ImageGeneration;
use App\Domains\Security\Services\ActivityLogger;
use Filament\Actions\DeleteAction;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ImageGenerationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->dateTime('j M Y H:i')->label('When')->sortable(),

                TextColumn::make('owner.email')->label('Customer')->searchable()->visibleFrom('md'),

                // The description is the customer's own words about their own
                // picture. Truncated hard: this screen exists to run the
                // feature, and a full-width transcript of what everybody asked
                // for is not operations.
                TextColumn::make('prompt')
                    ->label('Asked for')
                    ->limit(48)
                    ->wrap()
                    ->searchable(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (?string $state) => match ($state) {
                        ImageGeneration::COMPLETED => 'success',
                        ImageGeneration::FAILED => 'danger',
                        ImageGeneration::CANCELLED => 'gray',
                        default => 'warning',
                    })
                    // Scrubbed before it was stored, so a provider's error
                    // cannot show a credential back to whoever reads this.
                    ->description(fn (ImageGeneration $record) => $record->failure_reason),

                TextColumn::make('model.display_name')->label('Model')->placeholder('—')->visibleFrom('lg'),

                TextColumn::make('size')->visibleFrom('lg'),

                TextColumn::make('credit_cost')
                    ->label('Credits')
                    ->numeric(decimalPlaces: 2)
                    ->summarize(Sum::make()->numeric(decimalPlaces: 2))
                    ->visibleFrom('md'),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    ImageGeneration::QUEUED => 'Queued',
                    ImageGeneration::GENERATING => 'Generating',
                    ImageGeneration::COMPLETED => 'Completed',
                    ImageGeneration::FAILED => 'Failed',
                    ImageGeneration::CANCELLED => 'Cancelled',
                ]),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->label('Delete')
                    ->modalDescription('This removes the image and its record. Use it for a reported image, not for tidying up.')
                    // Authorise, act, AUDIT — the third one is what makes
                    // reaching into a customer's content accountable.
                    ->after(function (ImageGeneration $record) {
                        if ($record->file) {
                            app(FileStorage::class)->delete($record->file);
                        }

                        app(ActivityLogger::class)->log(
                            action: 'media.image.deleted',
                            subject: $record,
                            before: ['status' => $record->status, 'user_id' => $record->user_id],
                            after: ['deleted' => true],
                        );
                    }),
            ]);
    }
}
