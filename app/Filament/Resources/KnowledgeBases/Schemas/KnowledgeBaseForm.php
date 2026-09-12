<?php

namespace App\Filament\Resources\KnowledgeBases\Schemas;

use App\Domains\AI\Models\AiModel;
use App\Domains\AI\Support\Capability;
use App\Domains\Files\Services\ExtractorRegistry;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class KnowledgeBaseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('The collection')->schema([
                TextInput::make('name')->required()->maxLength(120)->columnSpanFull(),

                Textarea::make('description')
                    ->rows(2)
                    ->maxLength(500)
                    ->helperText('For your own records. Customers see the name only.')
                    ->columnSpanFull(),

                Toggle::make('is_active')
                    ->label('Available to search')
                    ->default(true)
                    ->helperText('Switching this off stops it being searched immediately. Nothing is deleted.'),

                Placeholder::make('readable_types')
                    ->label('Aziv AI can read')
                    ->content(fn () => strtoupper(implode(', ', app(ExtractorRegistry::class)->extensions()))),
            ])->columns(2),

            /**
             * Retrieval settings (§17: "configure retrieval settings").
             *
             * Every one of these is a trade an owner should be able to make,
             * and every helper text says which way. Defaults are chosen to be
             * safe rather than impressive: a low bar for relevance is how a
             * document about office hours confidently answers a question about
             * tax.
             */
            Section::make('How it is searched')->schema([
                TextInput::make('top_k')
                    ->label('Passages per answer')
                    ->numeric()->required()->minValue(1)->maxValue(20)->default(5)
                    ->helperText('More is not better: every passage spends context the conversation itself needs.'),

                TextInput::make('min_score')
                    ->label('Minimum relevance (0–1)')
                    ->numeric()->required()->minValue(0)->maxValue(1)->step(0.01)->default(0.25)
                    ->helperText('Below this a passage is not evidence, it is noise. Raise it if answers cite irrelevant documents.'),

                TextInput::make('chunk_size')
                    ->label('Passage length (characters)')
                    ->numeric()->required()->minValue(300)->maxValue(8000)->default(1200)
                    ->helperText('Applies to documents added from now on. Existing documents keep the size they were indexed at.'),

                TextInput::make('chunk_overlap')
                    ->label('Overlap (characters)')
                    ->numeric()->required()->minValue(0)->maxValue(2000)->default(180)
                    ->helperText('Stops a fact that straddles a boundary being lost from both passages.'),

                Select::make('embedding_model_id')
                    ->label('Indexing model')
                    ->options(fn () => AiModel::query()
                        ->whereHas('capabilities', fn ($q) => $q
                            ->where('capability', Capability::EMBEDDINGS)
                            ->where('is_supported', true))
                        ->where('is_enabled', true)
                        ->pluck('display_name', 'id')
                        ->all())
                    ->placeholder('Choose automatically')
                    // Changing it does NOT re-index, and vectors from two
                    // models cannot be compared — so the warning is on the
                    // field rather than in a document nobody reads.
                    ->helperText('Changing this affects documents added afterwards. Existing documents keep working but are searched separately — re-add them to move everything onto one model.')
                    ->columnSpanFull(),
            ])->columns(2),
        ]);
    }
}
