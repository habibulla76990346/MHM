<?php

namespace App\Filament\Resources\TaxJurisdictions\Schemas;

use App\Domains\Billing\Models\Country;
use App\Domains\Tax\Models\TaxRate;
use App\Domains\Tax\Models\TaxRule;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TaxJurisdictionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Where these rules apply')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->required()->maxLength(120)
                        ->helperText('Your own label. It appears on invoices, so write it the way you want it read.'),

                    Select::make('country')
                        ->options(fn () => Country::orderBy('name')->pluck('name', 'code'))
                        ->required()->searchable(),

                    TextInput::make('state')
                        ->maxLength(64)
                        ->helperText('Leave empty to cover the whole country. A jurisdiction naming a state wins over one that does not.'),

                    TextInput::make('priority')
                        ->numeric()->default(50)
                        ->helperText('Lower numbers are checked first.'),

                    Toggle::make('is_domestic')
                        ->label('This is your home country')
                        ->default(true),

                    Toggle::make('is_active')
                        ->label('Active')
                        ->helperText('Nothing is charged from an inactive jurisdiction.'),
                ]),

            Section::make('Rates')
                ->description('Each row is one component that appears on the invoice. Name them however your accountant needs them named — nothing in Aziv AI knows or assumes what they are called.')
                ->schema([
                    Repeater::make('rates')
                        ->relationship()
                        ->defaultItems(0)
                        ->columns(3)
                        ->itemLabel(fn (array $state) => trim(($state['name'] ?? 'Rate').' — '.($state['rate_percent'] ?? '0').'%'))
                        ->schema([
                            TextInput::make('name')->required()->maxLength(64),

                            TextInput::make('code')->maxLength(32)
                                ->helperText('Optional short code.'),

                            TextInput::make('rate_percent')
                                ->numeric()->required()->minValue(0)->maxValue(100)
                                ->suffix('%'),

                            Select::make('applies_to')
                                ->options([
                                    'all' => 'Everything',
                                    'subscription' => 'Subscriptions only',
                                    'credits' => 'Credit top-ups only',
                                ])
                                ->default('all')->required(),

                            DatePicker::make('effective_from')
                                ->required()
                                ->default(now())
                                ->helperText('Invoices are computed from the rate in force on THEIR date, so changing this never alters an invoice already issued.'),

                            DatePicker::make('effective_until')
                                ->helperText('Leave empty while it is current.'),

                            Toggle::make('is_active')->default(true),
                        ]),
                ]),

            Section::make('Rules')
                ->description('Which rates apply to whom. Aziv AI only compares the customer’s location and registration to yours — what that means for tax is your decision.')
                ->schema([
                    Repeater::make('rules')
                        ->relationship()
                        ->defaultItems(0)
                        ->columns(2)
                        ->itemLabel(fn (array $state) => TaxRule::conditions()[$state['condition_type'] ?? ''] ?? 'Rule')
                        ->schema([
                            Select::make('condition_type')
                                ->options(TaxRule::conditions())
                                ->required(),

                            TextInput::make('priority')->numeric()->default(10),

                            Select::make('rate_ids')
                                ->label('Rates to apply')
                                ->multiple()
                                ->required()
                                ->options(fn ($livewire) => TaxRate::where('jurisdiction_id', $livewire->record?->getKey())
                                    ->get()
                                    ->mapWithKeys(fn (TaxRate $r) => [$r->getKey() => $r->name.' — '.rtrim(rtrim((string) $r->rate_percent, '0'), '.').'%'])
                                    ->all())
                                ->helperText('Save the rates above first, then choose them here.')
                                ->columnSpanFull(),

                            Toggle::make('is_active')->default(true),
                        ]),
                ]),
        ]);
    }
}
