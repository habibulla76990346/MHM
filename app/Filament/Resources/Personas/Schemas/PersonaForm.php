<?php

namespace App\Filament\Resources\Personas\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class PersonaForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(120),

            TextInput::make('description')
                ->maxLength(255)
                ->helperText('For your own reference. Customers do not see this.'),

            Textarea::make('system_prompt')
                ->label('Instructions to the AI')
                ->required()
                ->rows(8)
                ->columnSpanFull()
                ->helperText(
                    'Written in plain language, as if briefing a new member of staff. '
                    .'For example: "You are a support agent for Aziv AI. Be concise. If you do not know, say so."'
                ),

            Toggle::make('is_default')
                ->label('Use this for new chats')
                ->helperText('Only one persona can be the default; setting this clears the other.'),

            TextInput::make('sort_order')->numeric()->default(0),
        ])->columns(2);
    }
}
