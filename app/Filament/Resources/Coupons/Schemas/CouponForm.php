<?php

namespace App\Filament\Resources\Coupons\Schemas;

use App\Domains\Billing\Models\Coupon;
use App\Domains\Billing\Models\Currency;
use App\Domains\Billing\Models\Plan;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CouponForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('The offer')
                ->columns(2)
                ->schema([
                    TextInput::make('code')
                        ->required()->maxLength(48)
                        ->helperText('Case does not matter — it is stored and matched in capitals.'),

                    Select::make('type')
                        ->options([
                            Coupon::PERCENTAGE => 'A percentage off',
                            Coupon::FIXED => 'A fixed amount off',
                            Coupon::CREDITS => 'Free credits',
                        ])
                        ->default(Coupon::PERCENTAGE)
                        ->required()->live(),

                    TextInput::make('value')->numeric()->required()->minValue(0),

                    Select::make('currency')
                        ->options(fn () => Currency::orderBy('sort_order')->pluck('code', 'code'))
                        ->helperText('Only for a fixed amount.')
                        ->visible(fn ($get) => $get('type') === Coupon::FIXED),

                    TextInput::make('description')->maxLength(255)->columnSpanFull(),
                ]),

            Section::make('Limits')
                ->columns(2)
                ->schema([
                    TextInput::make('max_redemptions')
                        ->numeric()->minValue(1)
                        ->helperText('Leave empty for unlimited.'),

                    TextInput::make('max_per_user')->numeric()->default(1)->minValue(1),

                    DateTimePicker::make('valid_from')->seconds(false),
                    DateTimePicker::make('valid_until')->seconds(false),

                    Select::make('plan_restrictions')
                        ->label('Only these plans')
                        ->multiple()
                        ->options(fn () => Plan::orderBy('sort_order')->pluck('name', 'id'))
                        ->helperText('Leave empty to allow every plan.')
                        ->columnSpanFull(),

                    Toggle::make('is_active')->default(true),
                ]),
        ]);
    }
}
