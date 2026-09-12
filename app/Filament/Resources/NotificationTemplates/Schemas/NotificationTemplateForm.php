<?php

namespace App\Filament\Resources\NotificationTemplates\Schemas;

use App\Domains\Notifications\Models\NotificationTemplate;
use App\Domains\Notifications\Support\NotificationEvent;
use App\Domains\Notifications\Support\TemplateRenderer;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class NotificationTemplateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Which message')->schema([
                Select::make('event_key')
                    ->label('Event')
                    ->options(fn () => NotificationEvent::options())
                    ->required()
                    ->live()
                    ->disabled(fn (?NotificationTemplate $record) => $record !== null)
                    ->helperText('Chosen once. Which message this is cannot change afterwards, because the values it is given depend on it.'),

                Select::make('channel')
                    ->options(NotificationEvent::CHANNELS)
                    ->default(NotificationEvent::CHANNEL_MAIL)
                    ->required()
                    ->live()
                    ->disabled(fn (?NotificationTemplate $record) => $record !== null),

                Placeholder::make('what_it_is')
                    ->label('When this is sent')
                    ->content(fn ($get) => NotificationEvent::definition((string) $get('event_key'))['description']
                        ?? 'Choose an event above.')
                    ->columnSpanFull(),
            ])->columns(2),

            Section::make('The wording')
                ->description('Leave a field empty to keep the wording Aziv AI ships with.')
                ->schema([
                    /**
                     * The declared variables, with what each one means.
                     *
                     * Shown rather than left to be discovered, because the
                     * renderer substitutes THESE AND NOTHING ELSE — a
                     * placeholder that is not on this list is removed from the
                     * message rather than printed. An author who cannot see
                     * the list would find that out from a customer.
                     */
                    Placeholder::make('variables')
                        ->label('Values you can use')
                        ->content(fn ($get) => collect(NotificationEvent::variables((string) $get('event_key')))
                            ->map(fn (string $meaning, string $name) => '{{'.$name.'}} — '.$meaning)
                            ->implode(' · ') ?: 'Choose an event above.')
                        ->columnSpanFull(),

                    TextInput::make('subject')
                        ->maxLength(190)
                        ->placeholder(fn ($get) => NotificationEvent::definition((string) $get('event_key'))['subject'] ?? '')
                        ->rules([
                            fn ($get) => function (string $attribute, mixed $value, \Closure $fail) use ($get) {
                                self::rejectUnknown((string) $value, (string) $get('event_key'), $fail);
                            },
                        ])
                        ->columnSpanFull(),

                    Textarea::make('body')
                        ->rows(10)
                        ->maxLength(5000)
                        ->placeholder(fn ($get) => NotificationEvent::definition((string) $get('event_key'))['body'] ?? '')
                        ->rules([
                            fn ($get) => function (string $attribute, mixed $value, \Closure $fail) use ($get) {
                                self::rejectUnknown((string) $value, (string) $get('event_key'), $fail);
                            },
                        ])
                        ->helperText('Plain text. It is placed into your themed email shell and shown exactly as written.')
                        ->columnSpanFull(),

                    Toggle::make('is_active')
                        ->label('Send this message')
                        ->default(true)
                        ->helperText('Switch off to stop this notification entirely. Deleting the row instead restores the wording Aziv AI ships with.'),
                ]),
        ]);
    }

    /**
     * Refuse a placeholder the event does not provide.
     *
     * CAUGHT HERE RATHER THAN AT SEND TIME. A `{{card_number}}` in a template
     * renders as nothing, so without this an owner would ship a half-empty
     * email and only learn about it from a customer who received one.
     */
    private static function rejectUnknown(string $value, string $eventKey, \Closure $fail): void
    {
        if ($value === '' || ! NotificationEvent::exists($eventKey)) {
            return;
        }

        $unknown = TemplateRenderer::unknownPlaceholders($value, NotificationEvent::variables($eventKey));

        if ($unknown !== []) {
            $fail('This message does not provide '.implode(', ', array_map(
                fn (string $name) => '{{'.$name.'}}',
                $unknown,
            )).'. It would be left out of what the customer reads.');
        }
    }
}
