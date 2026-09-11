<?php

namespace App\Filament\Resources\FeatureFlags\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class FeatureFlagForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('key')
                ->required()
                ->maxLength(64)
                ->unique(ignoreRecord: true)
                ->regex('/^[a-z0-9_.]+$/')
                ->helperText('Lower case, with underscores or dots. This is what the code checks.')
                ->disabled(fn ($record) => $record !== null),

            TextInput::make('name')->required()->maxLength(120),

            TextInput::make('description')
                ->maxLength(255)
                ->columnSpanFull()
                ->helperText('What switching this on actually changes.'),

            Toggle::make('is_enabled')->label('Switched on'),
        ]);
    }
}
