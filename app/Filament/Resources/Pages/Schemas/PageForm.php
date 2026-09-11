<?php

namespace App\Filament\Resources\Pages\Schemas;

use App\Domains\Content\Models\Page;
use App\Domains\Content\Support\SectionType;
use App\Domains\Content\Support\Seo;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class PageForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Page')->schema([
                TextInput::make('title')
                    ->required()
                    ->maxLength(190)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (string $state, Get $get, $set, ?Page $record) {
                        // Only fill a slug that has not been set. Changing the
                        // address of a page people have already linked to is a
                        // decision, not a side effect of fixing a typo.
                        if (! $record && blank($get('slug'))) {
                            $set('slug', Str::slug($state));
                        }
                    }),

                TextInput::make('slug')
                    ->required()
                    ->maxLength(190)
                    ->alphaDash()
                    ->unique(ignoreRecord: true)
                    ->helperText(fn (?Page $record) => 'The address: '.url('/p/').'/'.($record->slug ?? 'your-slug'))
                    ->disabled(fn (?Page $record) => $record?->is_system ?? false),

                Select::make('status')
                    ->options([
                        Page::STATUS_DRAFT => 'Draft — only you can see it',
                        Page::STATUS_PUBLISHED => 'Published',
                    ])
                    ->default(Page::STATUS_DRAFT)
                    ->required()
                    ->live(),

                DateTimePicker::make('published_at')
                    ->label('Publish at')
                    ->seconds(false)
                    ->visible(fn (Get $get) => $get('status') === Page::STATUS_PUBLISHED)
                    // Scheduling is a future published_at, not a third status,
                    // so there is one answer to "is this live right now?".
                    ->helperText('Leave empty to publish immediately, or set a future time to schedule it.'),

                Toggle::make('show_in_footer')->label('Link from the footer'),

                TextInput::make('sort_order')
                    ->numeric()
                    ->default(0)
                    ->helperText('Order in the footer. Lower first.'),
            ])->columns(2),

            Section::make('Content')
                ->description('Pages are built from blocks. Each one is responsive and follows your theme.')
                ->schema([
                    Repeater::make('sections')
                        ->relationship()
                        ->hiddenLabel()
                        ->orderColumn('sort_order')
                        ->collapsible()
                        ->collapsed()
                        ->cloneable()
                        ->itemLabel(fn (array $state) => SectionType::label($state['type'] ?? '')
                            .((($state['payload']['heading'] ?? '') !== '') ? ' — '.$state['payload']['heading'] : ''))
                        ->schema([
                            Select::make('type')
                                ->options(collect(SectionType::all())->map(fn ($t) => $t['label'])->all())
                                ->required()
                                ->live()
                                ->helperText(fn (Get $get) => SectionType::all()[$get('type')]['description'] ?? null),

                            Toggle::make('is_visible')
                                ->label('Visible')
                                ->default(true)
                                ->helperText('Hide a block without deleting it.'),

                            // The payload fields are rebuilt whenever the type
                            // changes, so the editor only ever shows fields the
                            // renderer will actually read.
                            ...self::payloadFields(),
                        ])
                        ->columns(2)
                        ->columnSpanFull(),
                ]),

            Section::make('Search and sharing')
                ->description('How this page looks in a search result and when someone shares the link.')
                ->collapsed()
                ->schema([
                    TextInput::make('seo.title')
                        ->label('Search title')
                        ->maxLength(Seo::TITLE_MAX)
                        ->helperText('Leave empty to use the page title. About '.Seo::TITLE_MAX.' characters show.'),

                    Textarea::make('seo.description')
                        ->label('Search description')
                        ->rows(3)
                        ->maxLength(Seo::DESCRIPTION_MAX)
                        ->helperText('Leave empty and Aziv AI uses the first text on the page. About '.Seo::DESCRIPTION_MAX.' characters show.'),

                    TextInput::make('seo.image')
                        ->label('Share image')
                        ->maxLength(190)
                        ->helperText('A path such as brand/logo-light-bg.png. Leave empty to use your app icon.'),
                ]),
        ]);
    }

    /**
     * Every field of every section type, each shown only for its own type.
     *
     * Driven from SectionType so the editor cannot drift from the renderer:
     * adding a block type is one entry in that class, not a change in three
     * places.
     *
     * @return array<int, mixed>
     */
    private static function payloadFields(): array
    {
        $components = [];

        foreach (SectionType::all() as $type => $definition) {
            foreach ($definition['fields'] as $field => $meta) {
                $component = match ($meta['type']) {
                    'textarea' => Textarea::make("payload.{$field}")->rows(3),
                    default => TextInput::make("payload.{$field}")->maxLength(500),
                };

                $components[] = $component
                    ->label($meta['label'])
                    ->helperText($meta['help'] ?? null)
                    ->visible(fn (Get $get) => $get('type') === $type)
                    ->columnSpanFull();
            }

            if ($repeats = $definition['repeats'] ?? null) {
                $rowFields = [];

                foreach ($repeats['fields'] as $field => $meta) {
                    $rowFields[] = match ($meta['type']) {
                        'textarea' => Textarea::make($field)->label($meta['label'])->rows(2),
                        'icon' => Select::make($field)->label($meta['label'])->options([
                            'chat' => 'Chat', 'library' => 'Library', 'image' => 'Image',
                            'user' => 'Person', 'shield' => 'Shield', 'dot' => 'Dot',
                        ]),
                        default => TextInput::make($field)->label($meta['label'])->maxLength(255),
                    };
                }

                $components[] = Repeater::make("payload.{$repeats['key']}")
                    ->label($repeats['label'])
                    // The cap is the renderer's too: items() slices to the same
                    // number, so the editor cannot promise more than appears.
                    ->maxItems($repeats['max'])
                    ->collapsible()
                    ->schema($rowFields)
                    ->visible(fn (Get $get) => $get('type') === $type)
                    ->columnSpanFull();
            }
        }

        return $components;
    }
}
