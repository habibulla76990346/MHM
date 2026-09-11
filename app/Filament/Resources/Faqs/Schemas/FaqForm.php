<?php

namespace App\Filament\Resources\Faqs\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class FaqForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('question')
                ->required()
                ->maxLength(255)
                ->columnSpanFull(),

            Textarea::make('answer')
                ->required()
                ->rows(5)
                ->helperText('Plain text. Leave a blank line between paragraphs.')
                ->columnSpanFull(),

            TextInput::make('category')
                ->default('general')
                ->maxLength(64)
                ->helperText('Questions are grouped by this. A page section can show one category or all of them.'),

            TextInput::make('sort_order')
                ->numeric()
                ->default(0)
                ->helperText('Lower numbers appear first.'),

            Toggle::make('is_published')
                ->label('Published')
                ->default(true)
                ->helperText('Unpublished questions are hidden everywhere, including inside page sections.'),
        ]);
    }
}
