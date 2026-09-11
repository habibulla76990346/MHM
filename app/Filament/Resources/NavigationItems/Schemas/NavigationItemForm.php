<?php

namespace App\Filament\Resources\NavigationItems\Schemas;

use App\Domains\Content\Models\NavigationItem;
use App\Domains\Content\Models\NavigationMenu;
use App\Domains\Content\Models\Page;
use App\Filament\Resources\NavigationItems\NavigationItemResource;
use Closure;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Route;

class NavigationItemForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Where it appears')->schema([
                Select::make('menu_id')
                    ->label('Menu')
                    ->options(fn (?NavigationItem $record) => NavigationItemResource::menuOptions($record))
                    ->required()
                    ->live()
                    ->rules([
                        fn (?NavigationItem $record): Closure => function (string $attribute, $value, Closure $fail) use ($record) {
                            $menu = NavigationMenu::find($value);

                            if (! $menu || ! $menu->max_items) {
                                return;
                            }

                            // Editing an item already in this menu does not
                            // consume another slot.
                            if ($record && $record->menu_id === $menu->getKey()) {
                                return;
                            }

                            if ($menu->items()->count() >= $menu->max_items) {
                                $fail(
                                    "\"{$menu->name}\" is full at {$menu->max_items} items. "
                                    .'This limit exists because a narrow phone cannot fit more without the taps '
                                    .'becoming too small to hit reliably. Remove an item first, or put this one in the More menu.'
                                );
                            }
                        },
                    ]),

                TextInput::make('key')
                    ->required()
                    ->maxLength(48)
                    ->alphaDash()
                    ->helperText('A short internal name. Not shown to anyone.'),

                TextInput::make('label')->required()->maxLength(60),

                Select::make('icon')
                    ->options([
                        'chat' => 'Chat', 'library' => 'Library', 'image' => 'Image',
                        'user' => 'Person', 'shield' => 'Shield', 'more' => 'More', 'dot' => 'Dot',
                    ])
                    ->helperText('Shown in the phone bottom bar and the sidebar.'),

                TextInput::make('sort_order')->numeric()->default(0)->helperText('Lower first.'),

                Toggle::make('is_visible')->label('Visible')->default(true),
            ])->columns(2),

            Section::make('Where it goes')->schema([
                Select::make('destination_type')
                    ->label('Destination')
                    ->options(NavigationItem::DESTINATIONS)
                    ->default('route')
                    ->required()
                    ->live(),

                Select::make('route_name')
                    ->label('Screen')
                    ->options(fn () => self::routeOptions())
                    ->searchable()
                    ->visible(fn (Get $get) => $get('destination_type') === 'route')
                    ->required(fn (Get $get) => $get('destination_type') === 'route'),

                Select::make('page_id')
                    ->label('Page')
                    ->options(fn () => Page::orderBy('title')->pluck('title', 'id')->all())
                    ->searchable()
                    ->visible(fn (Get $get) => $get('destination_type') === 'page')
                    ->required(fn (Get $get) => $get('destination_type') === 'page')
                    ->helperText('A draft page hides the link until it is published.'),

                TextInput::make('url')
                    ->label('Address')
                    ->maxLength(500)
                    ->visible(fn (Get $get) => $get('destination_type') === 'external')
                    ->required(fn (Get $get) => $get('destination_type') === 'external')
                    ->helperText('A full https:// address, or a path such as /pricing.')
                    ->rules([
                        // Only http(s) or a site-relative path. Everyone sees
                        // navigation, so a javascript: URL typed here would be
                        // everyone's problem rather than only the author's.
                        fn (): Closure => function (string $attribute, $value, Closure $fail) {
                            $value = trim((string) $value);

                            if (str_starts_with($value, '/') && ! str_starts_with($value, '//')) {
                                return;
                            }

                            $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

                            if (! in_array($scheme, ['http', 'https'], true)) {
                                $fail('Use a full https:// address, or a path starting with / such as /pricing.');
                            }
                        },
                    ]),
            ])->columns(2),

            Section::make('Who sees it')
                ->collapsed()
                ->schema([
                    CheckboxList::make('device_visibility')
                        ->label('Devices')
                        ->options(NavigationItem::DEVICES)
                        ->helperText('Leave all unticked to show on every device.')
                        ->columns(3),

                    TextInput::make('permission')
                        ->maxLength(100)
                        ->helperText(
                            'Optional. Hides the link from people without this permission. '
                            .'It does NOT protect the destination — the screen itself decides who may open it.'
                        ),

                    TextInput::make('feature_flag_key')
                        ->label('Feature flag')
                        ->maxLength(64)
                        ->helperText('Optional. Hides the link while that feature is switched off.'),
                ]),
        ]);
    }

    /**
     * The named customer-facing routes an item can point at.
     *
     * Filtered rather than listed by hand, so a route added in a later phase
     * appears here automatically and a removed one disappears.
     *
     * @return array<string, string>
     */
    private static function routeOptions(): array
    {
        $options = [];

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();

            if (! $name || ! in_array('GET', $route->methods(), true)) {
                continue;
            }

            // Admin panel routes, API and Livewire internals are not customer
            // navigation destinations.
            if (preg_match('/^(filament|livewire|api|sanctum|password|verification|admin)\./', $name)) {
                continue;
            }

            if (str_contains($route->uri(), '{')) {
                continue;
            }

            $options[$name] = $name.'  ('.($route->uri() === '/' ? '/' : '/'.$route->uri()).')';
        }

        ksort($options);

        return $options;
    }
}
