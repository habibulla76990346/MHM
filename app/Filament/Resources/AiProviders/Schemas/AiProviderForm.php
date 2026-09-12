<?php

namespace App\Filament\Resources\AiProviders\Schemas;

use App\Domains\AI\Adapters\CustomHttpAdapter;
use App\Domains\AI\Models\AiProvider;
use App\Domains\AI\Services\ProviderRegistry;
use App\Domains\AI\Support\Capability;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
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
            /**
             * Adding a known provider should not begin with hunting for a base
             * URL in someone else's documentation. Choosing one here fills in
             * the settings that are FACTS about that provider — the address,
             * how it wants the key — and leaves every one of them editable.
             *
             * Nothing here is required: a provider with no preset is added the
             * same way, by typing the same three fields.
             */
            Section::make('Start from a known provider')
                ->description('Optional. Fills in the settings for a provider Aziv AI already knows, so you only have to add the key.')
                ->visible(fn (?AiProvider $record) => $record === null)
                ->schema([
                    Select::make('preset')
                        ->label('Provider')
                        ->dehydrated(false)
                        ->options(fn () => app(ProviderRegistry::class)->presetOptions())
                        ->searchable()
                        ->live()
                        ->afterStateUpdated(function (?string $state, $set) {
                            $preset = app(ProviderRegistry::class)->preset($state);

                            if (! $preset) {
                                return;
                            }

                            $set('name', $preset['name']);
                            $set('slug', Str::slug($preset['name']));
                            $set('adapter_type', $preset['adapter_type']);
                            $set('api_base_url', $preset['api_base_url']);
                            $set('auth_method', $preset['auth_method']);
                        })
                        ->helperText('You can change anything it fills in.'),

                    Placeholder::make('preset_key_source')
                        ->label('Where to get a key')
                        ->content(fn ($get) => app(ProviderRegistry::class)->preset($get('preset'))['key_source']
                            ?? 'Choose a provider above, or enter the details yourself.')
                        ->visible(fn ($get) => filled($get('preset'))),

                    Placeholder::make('preset_note')
                        ->label('Worth knowing')
                        ->content(fn ($get) => app(ProviderRegistry::class)->preset($get('preset'))['note'] ?? '')
                        ->visible(fn ($get) => filled(app(ProviderRegistry::class)->preset($get('preset'))['note'] ?? null)),
                ]),

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

            /**
             * The mapping builder (§12, Phase 7).
             *
             * For a provider that copies nobody's shape. An administrator
             * describes the request and where to find the answer in the
             * response, and the custom adapter drives it — no code, and no
             * release.
             *
             * A template is CONFIGURATION, never code: the placeholders are a
             * closed set substituted literally, so nothing typed here can be
             * evaluated.
             */
            Section::make('Request and response mapping')
                ->description('Only for a custom API. Describe what to send and where the answer is.')
                ->visible(fn ($get) => $get('adapter_type') === CustomHttpAdapter::KEY)
                ->schema([
                    Placeholder::make('placeholders')
                        ->label('Placeholders you can use')
                        ->content(fn () => collect(CustomHttpAdapter::PLACEHOLDERS)
                            ->map(fn (string $meaning, string $token) => $token.' — '.$meaning)
                            ->implode(' · ')),

                    Repeater::make('mappings')
                        ->relationship()
                        ->label('Mappings')
                        ->defaultItems(0)
                        ->itemLabel(fn (array $state) => ucfirst((string) ($state['capability'] ?? 'mapping')))
                        ->schema([
                            Select::make('capability')
                                ->options(fn () => collect(Capability::keys())
                                    ->mapWithKeys(fn (string $key) => [$key => Capability::label($key)])
                                    ->all())
                                ->default(Capability::CHAT)
                                ->required(),

                            Select::make('http_method')
                                ->options(['POST' => 'POST', 'GET' => 'GET'])
                                ->default('POST')
                                ->required(),

                            TextInput::make('endpoint_path')
                                ->required()
                                ->helperText('Added to the API address, for example: v1/generate')
                                ->columnSpanFull(),

                            KeyValue::make('request_template')
                                ->label('What to send')
                                ->keyLabel('Field')
                                ->valueLabel('Value or placeholder')
                                ->helperText('Use a placeholder on its own to send it as its real type — a list stays a list, a number stays a number.')
                                ->columnSpanFull(),

                            KeyValue::make('response_mapping')
                                ->label('Where the answer is')
                                ->keyLabel('We need')
                                ->valueLabel('Path in their response')
                                ->default(['content' => '', 'input_tokens' => '', 'output_tokens' => ''])
                                ->helperText('Dotted paths, for example result.output — leave the token paths empty if the provider does not report usage.')
                                ->columnSpanFull(),

                            KeyValue::make('headers_template')
                                ->label('Extra headers')
                                ->keyLabel('Header')
                                ->valueLabel('Value')
                                ->helperText('Write '.CustomHttpAdapter::CREDENTIAL_PLACEHOLDER.' where the key goes, so the key itself is never stored here in plain text.')
                                ->columnSpanFull(),
                        ])
                        ->columns(2),
                ]),

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
