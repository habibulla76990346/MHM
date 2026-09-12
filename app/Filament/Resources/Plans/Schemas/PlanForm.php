<?php

namespace App\Filament\Resources\Plans\Schemas;

use App\Domains\AI\Models\AiModel;
use App\Domains\AI\Models\AiProvider;
use App\Domains\Billing\Models\Currency;
use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Models\PlanFeature;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PlanForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('The plan')
                ->columns(2)
                ->schema([
                    TextInput::make('name')->required()->maxLength(96),

                    Select::make('billing_cycle')
                        ->options(Plan::CYCLES)
                        ->default('monthly')
                        ->required()
                        ->helperText('A free plan still has periods — that is how its allowance refreshes.'),

                    Textarea::make('description')->rows(2)->columnSpanFull(),

                    Textarea::make('highlights')
                        ->rows(4)
                        ->columnSpanFull()
                        ->helperText('One selling point per line. Shown on the pricing page.'),

                    TextInput::make('trial_days')
                        ->numeric()->default(0)->minValue(0)->maxValue(365)
                        ->helperText('0 means no trial.'),

                    TextInput::make('sort_order')->numeric()->default(0),
                ]),

            Section::make('Credits')
                ->columns(2)
                ->description('Credits are what AI usage is charged against. A plan can include none and sell features only.')
                ->schema([
                    TextInput::make('credits_per_period')
                        ->numeric()->default(0)->minValue(0)
                        ->helperText('Granted at the start of every period.'),

                    Toggle::make('credits_rollover')
                        ->label('Unused credits carry over')
                        ->helperText('Off means the allowance resets each period. Credits a customer bought separately are never swept away.'),

                    TextInput::make('credit_expiry_days')
                        ->numeric()->minValue(1)->maxValue(3650)
                        ->helperText('Leave empty for credits that never expire.'),
                ]),

            Section::make('Price')
                ->description('Priced deliberately per market. A converted price moves daily and looks like a mistake.')
                ->schema([
                    Repeater::make('prices')
                        ->relationship()
                        ->defaultItems(0)
                        ->columns(3)
                        ->schema([
                            Select::make('currency')
                                ->options(fn () => Currency::orderBy('sort_order')->pluck('code', 'code'))
                                ->required()
                                ->searchable(),

                            TextInput::make('amount')->numeric()->required()->minValue(0),

                            Toggle::make('is_active')->default(true),
                        ]),
                ]),

            Section::make('Limits')
                ->description('A limit left as “No limit” does not restrict anything. Zero means none allowed — they are not the same.')
                ->schema([
                    Repeater::make('features')
                        ->relationship()
                        ->defaultItems(0)
                        ->columns(3)
                        ->itemLabel(fn (array $state) => PlanFeature::KNOWN[$state['key'] ?? ''] ?? ($state['key'] ?? 'Limit'))
                        ->schema([
                            Select::make('key')
                                ->options(PlanFeature::KNOWN)
                                ->required()
                                ->searchable(),

                            TextInput::make('value')
                                ->helperText('A number, or 1/0 for a yes-or-no feature.'),

                            Select::make('limit_type')
                                ->options([
                                    PlanFeature::HARD => 'Hard — stop at the limit',
                                    PlanFeature::SOFT => 'Soft — allow, but tell me',
                                    PlanFeature::UNLIMITED => 'No limit',
                                ])
                                ->default(PlanFeature::HARD)
                                ->required(),
                        ]),
                ]),

            Section::make('What this plan may use')
                ->description('Leave both empty to allow everything. Only what you explicitly deny is blocked, so a newly added model does not silently disappear from every plan.')
                ->schema([
                    Repeater::make('modelAccess')
                        ->relationship()
                        ->label('Model rules')
                        ->defaultItems(0)
                        ->columns(2)
                        ->schema([
                            Select::make('ai_model_id')
                                ->label('Model')
                                ->options(fn () => AiModel::orderBy('display_name')->pluck('display_name', 'id'))
                                ->required()->searchable(),

                            Toggle::make('is_allowed')->label('Allowed')->default(true),
                        ]),

                    Repeater::make('providerAccess')
                        ->relationship()
                        ->label('Provider rules')
                        ->defaultItems(0)
                        ->columns(2)
                        ->schema([
                            Select::make('ai_provider_id')
                                ->label('Provider')
                                ->options(fn () => AiProvider::orderBy('name')->pluck('name', 'id'))
                                ->required()->searchable(),

                            Toggle::make('is_allowed')->label('Allowed')->default(true),
                        ]),
                ]),

            Section::make('Availability')
                ->columns(2)
                ->schema([
                    Select::make('status')
                        ->options([
                            Plan::STATUS_DRAFT => 'Draft — nobody can buy it',
                            Plan::STATUS_ACTIVE => 'Active',
                            Plan::STATUS_ARCHIVED => 'Archived — existing customers keep it',
                        ])
                        ->default(Plan::STATUS_DRAFT)
                        ->required(),

                    Toggle::make('is_public')
                        ->label('Show on the pricing page')
                        ->helperText('An active plan that is not public can still be assigned by an administrator.'),

                    Toggle::make('is_free')->label('This plan is free'),

                    Toggle::make('is_default')
                        ->label('New accounts start on this plan')
                        ->helperText('Publishing a default plan is what switches metering on for everybody. Only one plan can hold it.'),
                ]),
        ]);
    }
}
