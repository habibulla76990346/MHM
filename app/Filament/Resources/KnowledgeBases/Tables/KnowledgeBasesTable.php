<?php

namespace App\Filament\Resources\KnowledgeBases\Tables;

use App\Domains\Knowledge\Contracts\VectorStore;
use App\Domains\Knowledge\Models\Document;
use App\Domains\Knowledge\Models\KnowledgeBase;
use App\Domains\Knowledge\Stores\DatabaseVectorStore;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class KnowledgeBasesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->searchable()->weight('medium'),

                TextColumn::make('documents_count')
                    ->counts('documents')
                    ->label('Documents')
                    ->visibleFrom('sm'),

                TextColumn::make('passages')
                    ->label('Passages')
                    // A number an owner can act on: it is what a search reads.
                    ->state(fn (KnowledgeBase $record) => app(VectorStore::class)->count($record))
                    ->description(fn (KnowledgeBase $record) => app(VectorStore::class)->count($record) > DatabaseVectorStore::SCAN_CEILING
                        ? 'Large — searches read every passage'
                        : null)
                    ->visibleFrom('md'),

                TextColumn::make('grants_count')
                    ->counts('grants')
                    ->label('Granted to')
                    ->description(fn (KnowledgeBase $record) => $record->grants()->count() === 0
                        // Not an error, but almost always a mistake: an
                        // administrator who curated a collection and never
                        // granted it has built something nobody can search.
                        ? 'Nobody yet'
                        : null),

                TextColumn::make('failed')
                    ->label('Problems')
                    ->state(fn (KnowledgeBase $record) => $record->documents()
                        ->where('status', Document::STATUS_FAILED)->count() ?: null)
                    ->placeholder('—')
                    ->color('danger')
                    ->visibleFrom('lg'),

                TextColumn::make('is_active')
                    ->label('Status')
                    ->badge()
                    ->state(fn (KnowledgeBase $record) => $record->is_active ? 'Searchable' : 'Switched off')
                    ->color(fn (KnowledgeBase $record) => $record->is_active ? 'success' : 'gray'),
            ])
            ->emptyStateHeading('No shared collections yet')
            ->emptyStateDescription(
                'A shared collection is one you curate and grant to customers. Their own uploads are private to them and are not listed here.'
            )
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->modalDescription('The documents in it stop being searchable immediately. The uploaded files themselves are not deleted.'),
            ]);
    }
}
