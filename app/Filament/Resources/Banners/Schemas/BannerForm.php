<?php

namespace App\Filament\Resources\Banners\Schemas;

use App\Domains\Content\Models\Banner;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class BannerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Message')->schema([
                TextInput::make('title')->required()->maxLength(190)->columnSpanFull(),

                Textarea::make('body')->rows(3)->maxLength(1000)->columnSpanFull(),

                Select::make('variant')
                    ->label('Tone')
                    ->options(Banner::VARIANTS)
                    ->default('info')
                    ->required()
                    // Tone, not colour: the theme decides what "warning" looks
                    // like, so an announcement follows the site's appearance.
                    ->helperText('The colours come from your theme, so this stays readable whatever theme is live.'),

                TextInput::make('cta_label')->label('Button text')->maxLength(60),

                TextInput::make('cta_url')
                    ->label('Button link')
                    ->maxLength(500)
                    ->url()
                    ->helperText('A full https:// address, or a path such as /pricing.'),
            ])->columns(2),

            Section::make('When and who')->schema([
                DateTimePicker::make('starts_at')
                    ->label('Show from')
                    ->helperText('Leave empty to start immediately.')
                    ->seconds(false),

                DateTimePicker::make('ends_at')
                    ->label('Stop showing')
                    ->helperText('Leave empty to keep showing until you switch it off.')
                    ->seconds(false)
                    // A window that closes before it opens shows nothing, with
                    // no error — so it is refused at the point of entry.
                    ->after('starts_at'),

                Select::make('audience')
                    ->options(Banner::AUDIENCES)
                    ->default('everyone')
                    ->required(),

                TextInput::make('priority')
                    ->numeric()
                    ->default(0)
                    ->minValue(0)
                    ->maxValue(1000)
                    ->helperText('When several are live at once, the highest number wins.'),

                Toggle::make('is_dismissible')
                    ->label('Visitors can dismiss it')
                    ->default(true),

                Toggle::make('is_active')
                    ->label('Switched on')
                    ->default(true)
                    ->helperText('Off means nobody sees it, whatever the dates say.'),
            ])->columns(2),
        ]);
    }
}
