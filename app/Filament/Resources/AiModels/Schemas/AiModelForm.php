<?php

namespace App\Filament\Resources\AiModels\Schemas;

use App\Domains\AI\Models\AiModel;
use App\Domains\AI\Models\AiModelPrice;
use App\Domains\AI\Support\Capability;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AiModelForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Model')->schema([
                Select::make('provider_id')
                    ->label('Provider')
                    ->relationship('provider', 'name')
                    ->required()
                    ->searchable(),

                TextInput::make('model_identifier')
                    ->label('Identifier')
                    ->required()
                    ->maxLength(190)
                    ->helperText('Exactly as the provider spells it — this is what goes on the wire.'),

                TextInput::make('display_name')
                    ->label('Name customers see')
                    ->required()
                    ->maxLength(190),

                Select::make('status')
                    ->options(AiModel::STATUSES)
                    ->default(AiModel::STATUS_STABLE)
                    ->required()
                    ->helperText('Deprecated and disabled models are kept for your records but never used for new work.'),

                Select::make('modality')
                    ->label('What it handles')
                    ->options(AiModel::MODALITIES)
                    ->default('text')
                    ->required(),

                Toggle::make('is_enabled')
                    ->label('Available to customers')
                    // The gate: a synced model arrives switched off, and this
                    // is the moment an owner decides otherwise.
                    ->helperText('Models found by a catalog refresh arrive switched off, so a provider\'s release schedule never decides what your customers can spend money on.'),

                Textarea::make('description')->rows(2)->columnSpanFull(),
            ])->columns(2),

            Section::make('Limits and ranking')->schema([
                TextInput::make('context_window')
                    ->numeric()
                    ->helperText('How much text it can consider at once, in tokens.'),

                TextInput::make('max_output_tokens')
                    ->numeric()
                    ->helperText('The longest reply it will produce.'),

                TextInput::make('quality_rank')
                    ->numeric()
                    ->default(50)
                    ->minValue(0)
                    ->maxValue(100)
                    ->helperText('Your own judgement, used by the "Best quality" routing mode.'),

                TextInput::make('sort_order')->numeric()->default(0),
            ])->columns(2),

            Section::make('What it can do')
                ->description('The router asks for capabilities, never for a model by name — so this is what decides where a request can go.')
                ->schema([
                    CheckboxList::make('capability_keys')
                        ->hiddenLabel()
                        ->options(collect(Capability::all())->map(fn ($c) => $c['label'])->all())
                        ->descriptions(collect(Capability::all())->map(fn ($c) => $c['description'])->all())
                        ->columns(2),
                ]),

            Section::make('Pricing')
                ->description('What the provider charges you, and what you charge the customer. Old prices are kept so past usage stays costed at the rate that applied then.')
                ->collapsed()
                ->schema([
                    Repeater::make('prices')
                        ->relationship()
                        ->hiddenLabel()
                        // Starts empty. A model can exist before anyone knows
                        // what it costs, and forcing a price at creation time
                        // would mean inventing one.
                        ->defaultItems(0)
                        ->addActionLabel('Add a price')
                        ->schema([
                            Select::make('unit')->options(AiModelPrice::UNITS)->required(),

                            TextInput::make('provider_cost')
                                ->label('Your cost')
                                ->numeric()
                                ->default(0)
                                ->helperText('What the AI company charges you.'),

                            TextInput::make('credit_cost')
                                ->label('Customer price')
                                ->numeric()
                                ->default(0)
                                ->helperText('In credits. The gap is your margin.'),

                            TextInput::make('currency')->default('USD')->maxLength(3),

                            DateTimePicker::make('effective_from')
                                ->required()
                                ->default(now())
                                ->seconds(false),

                            DateTimePicker::make('effective_until')
                                ->seconds(false)
                                ->after('effective_from')
                                ->helperText('Leave empty while this is the current price.'),
                        ])
                        ->columns(3)
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
