<?php

namespace App\Filament\Resources\PaymentGateways\Schemas;

use App\Domains\Billing\Models\Country;
use App\Domains\Billing\Models\Currency;
use App\Domains\Billing\Models\Plan;
use App\Domains\Payments\Models\PaymentGatewayCredential;
use App\Domains\Payments\Models\PaymentGatewayRecord;
use App\Domains\Payments\Support\Capability;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PaymentGatewayForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('This gateway')
                ->columns(2)
                ->schema([
                    TextInput::make('name')->required()->maxLength(96),

                    Select::make('status')
                        ->options([
                            PaymentGatewayRecord::STATUS_ACTIVE => 'On — it can take payments',
                            PaymentGatewayRecord::STATUS_DISABLED => 'Off',
                        ])
                        ->required(),

                    Select::make('mode')
                        ->options([
                            PaymentGatewayRecord::MODE_SANDBOX => 'Sandbox — test payments only',
                            PaymentGatewayRecord::MODE_LIVE => 'Live — real money',
                        ])
                        ->required()
                        ->helperText('Each mode has its own credentials, so switching cannot pick up the wrong key.'),

                    TextInput::make('priority')
                        ->numeric()->default(100)
                        ->helperText('Lower is tried first when more than one gateway qualifies.'),

                    Toggle::make('is_default')->label('Use this one by default'),

                    Toggle::make('maintenance_mode')
                        ->label('Temporarily out of rotation')
                        ->helperText('Stops new payments without losing any configuration.'),

                    Placeholder::make('capabilities_display')
                        ->label('What it can do')
                        ->content(fn (?PaymentGatewayRecord $record) => $record
                            ? collect($record->declaredCapabilities())
                                ->map(fn (string $c) => Capability::label($c))
                                ->implode(' · ')
                            : '—')
                        ->helperText('Declared by its adapter. A subscription is never routed to a gateway that cannot renew one.')
                        ->columnSpanFull(),
                ]),

            Section::make('Credentials')
                ->description('Secrets are encrypted and never shown again — only the last four characters. The publishable key is different: it identifies you to the gateway’s own checkout script and is meant to be visible.')
                ->schema([
                    Repeater::make('credentials')
                        ->relationship()
                        ->defaultItems(0)
                        ->columns(2)
                        ->itemLabel(fn (array $state) => strtoupper($state['mode'] ?? 'mode').' — '.($state['hint'] ?? 'not set'))
                        ->schema([
                            Select::make('mode')
                                ->options([
                                    PaymentGatewayRecord::MODE_SANDBOX => 'Sandbox',
                                    PaymentGatewayRecord::MODE_LIVE => 'Live',
                                ])
                                ->required(),

                            Select::make('status')
                                ->options([
                                    PaymentGatewayCredential::STATUS_ACTIVE => 'In use',
                                    PaymentGatewayCredential::STATUS_DISABLED => 'Switched off',
                                ])
                                ->default(PaymentGatewayCredential::STATUS_ACTIVE)
                                ->required(),

                            TextInput::make('publishable_key')
                                ->label('Publishable key / merchant id')
                                ->helperText('Safe to render into the checkout page. It authorises nothing on its own.')
                                ->columnSpanFull(),

                            KeyValue::make('credentials')
                                ->label('Secret credentials')
                                ->keyLabel('Field')
                                ->valueLabel('Value')
                                ->helperText('Whatever fields this gateway needs — for example a key id and a key secret. Stored encrypted, never displayed again.')
                                ->columnSpanFull(),

                            TextInput::make('webhook_secret')
                                ->label('Webhook signing secret')
                                ->password()
                                ->revealable(false)
                                ->helperText('Without this every notification from the gateway is rejected.')
                                ->columnSpanFull(),
                        ]),
                ]),

            Section::make('Where it may be used')
                ->columns(2)
                ->schema([
                    Select::make('supported_currencies')
                        ->multiple()
                        ->options(fn () => Currency::orderBy('sort_order')->pluck('code', 'code'))
                        ->helperText('Leave empty for whatever your merchant account allows — that is a fact about your agreement, not something Aziv AI can determine.'),

                    Select::make('supported_countries')
                        ->multiple()
                        ->options(fn () => Country::orderBy('name')->pluck('name', 'code'))
                        ->helperText('Leave empty for no restriction.'),
                ]),

            Section::make('Routing rules')
                ->description('Which gateway handles what. A rule orders the gateways that can already do the job; it can never send a payment to one that cannot.')
                ->schema([
                    Repeater::make('rules')
                        ->relationship()
                        ->defaultItems(0)
                        ->columns(3)
                        ->schema([
                            Select::make('payment_type')
                                ->options([
                                    'subscription' => 'Subscriptions',
                                    'one_time' => 'One-off payments',
                                    'credit_topup' => 'Credit top-ups',
                                ])
                                ->required(),

                            Select::make('currency')
                                ->options(fn () => Currency::orderBy('sort_order')->pluck('code', 'code'))
                                ->placeholder('Any'),

                            Select::make('country')
                                ->options(fn () => Country::orderBy('name')->pluck('name', 'code'))
                                ->placeholder('Any'),

                            Select::make('plan_id')
                                ->label('Plan')
                                ->options(fn () => Plan::orderBy('sort_order')->pluck('name', 'id'))
                                ->placeholder('Any'),

                            TextInput::make('priority')->numeric()->default(100),

                            Toggle::make('is_active')->default(true),
                        ]),
                ]),
        ]);
    }
}
