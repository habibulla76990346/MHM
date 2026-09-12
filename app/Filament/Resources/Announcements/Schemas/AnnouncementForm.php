<?php

namespace App\Filament\Resources\Announcements\Schemas;

use App\Domains\Billing\Models\Plan;
use App\Domains\Notifications\Models\Announcement;
use App\Domains\Notifications\Services\AnnouncementService;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AnnouncementForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('The message')->schema([
                TextInput::make('title')
                    ->required()
                    ->maxLength(190)
                    ->helperText('This becomes the subject line and the heading in the app.')
                    ->columnSpanFull(),

                Textarea::make('body')
                    ->required()
                    ->rows(6)
                    ->maxLength(5000)
                    // Not a rich editor on purpose: the body is shown in an
                    // email and in the app, and markup that renders in one
                    // rarely renders in the other. Plain words work in both.
                    ->helperText('Plain text. Line breaks are kept; formatting and links are shown as written.')
                    ->columnSpanFull(),

                Select::make('level')
                    ->options(Announcement::LEVELS)
                    ->default('info')
                    ->required()
                    ->helperText('How it is marked in the app. A meaning, not a colour — the theme decides how it looks.'),
            ])->columns(2),

            Section::make('Who it goes to')->schema([
                Select::make('audience')
                    ->options(Announcement::AUDIENCES)
                    ->default(Announcement::AUDIENCE_EVERYONE)
                    ->required()
                    ->live(),

                Select::make('audience_plan_id')
                    ->label('Which plan')
                    ->options(fn () => Plan::orderBy('name')->pluck('name', 'id')->all())
                    ->required(fn ($get) => $get('audience') === Announcement::AUDIENCE_PLAN)
                    ->visible(fn ($get) => $get('audience') === Announcement::AUDIENCE_PLAN),

                Placeholder::make('audience_size')
                    ->label('That is currently')
                    ->content(function ($get) {
                        $preview = new Announcement([
                            'audience' => $get('audience') ?: Announcement::AUDIENCE_EVERYONE,
                            'audience_plan_id' => $get('audience_plan_id'),
                        ]);

                        $count = app(AnnouncementService::class)->audienceSize($preview);

                        return trans_choice('{0}nobody|{1}1 person|[2,*]:count people', $count, ['count' => $count]);
                    })
                    ->columnSpanFull(),

                Toggle::make('send_email')
                    ->label('Also send it by email')
                    // Off by default, deliberately. Mailing every customer is
                    // not something to do by forgetting a checkbox.
                    ->default(false)
                    ->helperText('It always appears in the app. Email reaches people who are not signed in — and cannot be taken back.')
                    ->columnSpanFull(),
            ])->columns(2),

            Section::make('When')->schema([
                Select::make('status')
                    ->options([
                        Announcement::STATUS_DRAFT => Announcement::STATUSES[Announcement::STATUS_DRAFT],
                        Announcement::STATUS_SCHEDULED => Announcement::STATUSES[Announcement::STATUS_SCHEDULED],
                    ])
                    ->default(Announcement::STATUS_DRAFT)
                    ->required()
                    ->live()
                    // "Sent" is not offered: it is something that HAPPENS to an
                    // announcement, not a state to be typed in.
                    ->helperText('A draft goes nowhere. Scheduled sends at the time below, or straight away if you leave it empty.'),

                DateTimePicker::make('send_at')
                    ->label('Send at')
                    ->seconds(false)
                    ->visible(fn ($get) => $get('status') === Announcement::STATUS_SCHEDULED)
                    ->helperText('Leave empty to send on the next hourly run.'),
            ])->columns(2),
        ]);
    }
}
