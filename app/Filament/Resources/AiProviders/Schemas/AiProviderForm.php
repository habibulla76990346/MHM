<?php

namespace App\Filament\Resources\AiProviders\Schemas;

use App\Domains\AI\Models\AiProvider;
use App\Domains\AI\Services\ProviderRegistry;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class AiProviderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Provider')->schema([
                TextInput::make('name')
                    ->required()
                    ->maxLength(120)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (string $state, $set, $get, ?AiProvider $record) {
                        if (! $record && blank($get('slug'))) {
                            $set('slug', Str::slug($state));
                        }
                    }),

                TextInput::make('slug')
                    ->required()
                    ->alphaDash()
                    ->maxLength(120)
                    ->unique(ignoreRecord: true)
                    ->disabled(fn (?AiProvider $record) => $record !== null)
                    ->helperText('An internal name. Cannot be changed once saved, because logs refer to it.'),

                Select::make('adapter_type')
                    ->label('How Aziv AI talks to it')
                    ->options(fn () => app(ProviderRegistry::class)->options())
                    ->required()
                    ->live()
                    ->helperText('Most providers copy OpenAI\'s API, so the first option usually works without any development.'),

                TextInput::make('api_base_url')
                    ->label('API address')
                    ->url()
                    ->required()
                    ->maxLength(255)
                    ->helperText('From the provider\'s own documentation, for example https://api.example.com/v1'),

                Select::make('auth_method')
                    ->label('How the key is sent')
                    ->options(AiProvider::AUTH_METHODS)
                    ->default('bearer')
                    ->required(),

                Select::make('account_class')
                    ->label('Account type')
                    ->options(AiProvider::ACCOUNT_CLASSES)
                    ->default('unknown')
                    ->helperText('Used by the "Free only" routing mode.'),
            ])->columns(2),

            Section::make('Availability')->schema([
                Select::make('status')
                    ->options(AiProvider::STATUSES)
                    ->default(AiProvider::STATUS_DISABLED)
                    ->required()
                    ->helperText('A new provider starts disabled. Test the connection first, then switch it on.'),

                Toggle::make('maintenance_mode')
                    ->label('Temporarily route around this provider')
                    // Two different decisions, kept separate: "not using this
                    // provider" and "skip it for now" must not overwrite each
                    // other.
                    ->helperText('Keeps the settings and the key, but stops sending requests. Use this rather than disabling for a short outage.'),

                TextInput::make('priority')
                    ->numeric()
                    ->default(100)
                    ->helperText('Lower is preferred when several providers can serve the same request.'),

                TextInput::make('timeout_seconds')
                    ->numeric()
                    ->default(60)
                    ->minValue(5)
                    ->maxValue(300)
                    ->helperText('How long to wait for an answer before giving up.'),

                TextInput::make('max_retries')
                    ->numeric()
                    ->default(2)
                    ->minValue(0)
                    ->maxValue(5)
                    ->helperText('Only failures worth retrying are retried — a rejected key never is.'),

                TextInput::make('region')
                    ->maxLength(32)
                    ->helperText('Optional. For your own records.'),
            ])->columns(2),
        ]);
    }
}
