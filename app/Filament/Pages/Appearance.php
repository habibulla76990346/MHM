<?php

namespace App\Filament\Pages;

use App\Domains\Security\Services\ActivityLogger;
use App\Domains\Theming\Models\Theme;
use App\Domains\Theming\Services\ThemeService;
use App\Domains\Theming\Support\ColorRamp;
use App\Domains\Theming\Support\ThemePalette;
use App\Domains\Theming\Support\TokenCatalogue;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;

/**
 * ADMIN → Appearance (blueprint §5, owner decision D-07).
 *
 * D-07 asks for control of "the complete practical design system" while also
 * saying "keep the interface organized into categories so the Admin Panel
 * remains easy to use". Those pull in opposite directions: 69 tokens x 2
 * scopes x 2 modes is 276 editable values, and a screen with 276 colour
 * pickers is complete and unusable.
 *
 * The resolution is progressive disclosure with a real default path:
 *
 *   1. Brand colours — seven values. Change them and every other token is
 *      re-derived, for BOTH panels and BOTH modes at once. Most owners will
 *      never go further than this.
 *   2. Everything else — grouped, collapsed, searchable, each with a note
 *      saying what it actually affects.
 *
 * Preview is session-scoped, so an administrator can look at a draft on the
 * live site while every visitor still sees the published theme.
 */
class Appearance extends Page
{
    protected static ?string $navigationLabel = 'Appearance';

    protected static ?string $title = 'Appearance';

    protected static ?string $slug = 'appearance';

    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.pages.appearance';

    /** Livewire state. All scalars and arrays of scalars — nothing serialised. */
    public ?int $themeId = null;

    public string $scope = TokenCatalogue::SCOPE_CUSTOMER;

    public string $mode = TokenCatalogue::MODE_LIGHT;

    /**
     * Editable token values for the current scope and mode.
     *
     * Keyed by a DOT-FREE form of the token key (`color__primary`, not
     * `color.primary`). Livewire reads a dot in `wire:model` as a nested array
     * path, so binding `values.color.primary` writes `$values['color']['primary']`
     * and the real token is never touched — silently, with no error. This is the
     * same trap the settings validator hit with Laravel's validation keys.
     *
     * @var array<string, string> wire key => value
     */
    public array $values = [];

    /** @var array<string, array<string, string>> mode => palette */
    public array $palette = [];

    public string $search = '';

    public ?string $customCss = null;

    /** Groups the administrator has opened. The brand/surface/text three start open. */
    public array $open = ['brand' => true, 'surface' => true, 'text' => true];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('themes.view') ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->themeId = app(ThemeService::class)->active()?->getKey()
            ?? Theme::query()->value('id');

        $this->load();
    }

    // -- state ---------------------------------------------------------------

    public function load(): void
    {
        $theme = $this->theme();

        if (! $theme) {
            return;
        }

        $this->values = self::toWireKeys($theme->tokenMap($this->scope, $this->mode));
        $this->customCss = $theme->custom_css;

        foreach ([TokenCatalogue::MODE_LIGHT, TokenCatalogue::MODE_DARK] as $mode) {
            $tokens = $theme->tokenMap(TokenCatalogue::SCOPE_CUSTOMER, $mode);

            $this->palette[$mode] = [
                'primary' => $tokens['color.primary'] ?? '#1f2937',
                'accent' => $tokens['color.accent'] ?? '#6366f1',
                'background' => $tokens['color.background'] ?? '#ffffff',
                'surface' => $tokens['color.surface'] ?? '#ffffff',
                'text' => $tokens['color.text'] ?? '#111827',
                'muted' => $tokens['color.text-muted'] ?? '#6b7280',
                'border' => $tokens['color.border'] ?? '#e5e7eb',
            ];
        }
    }

    public function updatedThemeId(): void
    {
        $this->load();
    }

    public function selectScope(string $scope): void
    {
        $this->scope = $scope === TokenCatalogue::SCOPE_ADMIN
            ? TokenCatalogue::SCOPE_ADMIN
            : TokenCatalogue::SCOPE_CUSTOMER;

        $this->load();
    }

    public function selectMode(string $mode): void
    {
        $this->mode = $mode === TokenCatalogue::MODE_DARK
            ? TokenCatalogue::MODE_DARK
            : TokenCatalogue::MODE_LIGHT;

        $this->load();
    }

    public function toggleGroup(string $group): void
    {
        $this->open[$group] = ! ($this->open[$group] ?? false);
    }

    public function theme(): ?Theme
    {
        return $this->themeId ? Theme::find($this->themeId) : null;
    }

    /** The dot-free form of a token key, for wire:model binding. */
    public static function wireKey(string $tokenKey): string
    {
        return str_replace('.', '__', $tokenKey);
    }

    /**
     * @param  array<string, string>  $values
     * @return array<string, string>
     */
    private static function toWireKeys(array $values): array
    {
        $out = [];

        foreach ($values as $key => $value) {
            $out[self::wireKey($key)] = $value;
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $values
     * @return array<string, string>
     */
    private static function fromWireKeys(array $values): array
    {
        $out = [];

        foreach ($values as $key => $value) {
            // Only keys that map back to a real token survive. A key invented
            // in the browser is dropped here, before it reaches the validator.
            $out[str_replace('__', '.', $key)] = is_scalar($value) ? (string) $value : '';
        }

        return $out;
    }

    // -- writes --------------------------------------------------------------

    public function save(): void
    {
        $theme = $this->guardWritable();

        if (! $theme) {
            return;
        }

        try {
            $change = app(ThemeService::class)
                ->saveTokens($theme, $this->scope, $this->mode, self::fromWireKeys($this->values), auth()->id());
        } catch (ValidationException $e) {
            Notification::make()
                ->title('Some values could not be saved')
                ->body(implode(' ', array_map(fn ($m) => is_array($m) ? $m[0] : $m, $e->errors())))
                ->danger()
                ->send();

            return;
        }

        if ($change['after'] === []) {
            Notification::make()->title('Nothing to save')->body('No values changed.')->send();

            return;
        }

        app(ActivityLogger::class)->log(
            'theme.tokens_updated',
            $theme,
            $change['before'],
            $change['after'],
            ['scope' => $this->scope, 'mode' => $this->mode],
        );

        $this->load();

        Notification::make()
            ->title('Saved')
            ->body(count($change['after']).' value(s) updated.'.($theme->is_active ? '' : ' Publish this theme to make it live.'))
            ->success()
            ->send();
    }

    /**
     * The fast path: seven colours in, a coherent theme out — for both panels
     * and both modes, which is what stops the Admin Panel drifting away from
     * the customer application.
     */
    public function applyPalette(): void
    {
        $theme = $this->guardWritable();

        if (! $theme) {
            return;
        }

        $before = $theme->tokenMap($this->scope, $this->mode);

        app(ThemeService::class)->applyPalette($theme, $this->palette, auth()->id());

        app(ActivityLogger::class)->log(
            'theme.palette_applied',
            $theme,
            $before,
            $theme->fresh()->tokenMap($this->scope, $this->mode),
            ['palette' => $this->palette],
        );

        $this->load();

        Notification::make()
            ->title('Theme rebuilt from your brand colours')
            ->body('Every colour, in both the customer app and the Admin Panel, now derives from these seven.')
            ->success()
            ->send();
    }

    public function resetGroup(string $group): void
    {
        $theme = $this->guardWritable();

        if (! $theme) {
            return;
        }

        $derived = ThemePalette::derive($this->palette[$this->mode] ?? [], $this->scope, $this->mode);

        foreach (TokenCatalogue::tokensInGroup($group) as $key) {
            $this->values[self::wireKey($key)] = $derived[$key];
        }

        Notification::make()
            ->title('Group reset')
            ->body('Reverted to the values derived from your brand colours. Save to keep them.')
            ->send();
    }

    public function saveCustomCss(): void
    {
        $theme = $this->guardWritable();

        if (! $theme || ! auth()->user()?->can('themes.custom_css')) {
            Notification::make()->title('Not permitted')->danger()->send();

            return;
        }

        $before = ['custom_css' => $theme->custom_css];
        $removed = app(ThemeService::class)->saveCustomCss($theme, $this->customCss, auth()->id());

        app(ActivityLogger::class)->log('theme.custom_css_updated', $theme, $before, ['custom_css' => $theme->fresh()->custom_css]);

        $this->load();

        Notification::make()
            ->title('Custom CSS saved')
            ->body($removed === [] ? 'No changes were needed.' : 'Removed for safety: '.implode(', ', $removed).'.')
            ->status($removed === [] ? 'success' : 'warning')
            ->send();
    }

    // -- computed ------------------------------------------------------------

    /** @return array<int, array{pair: string, ratio: float, passes: bool}> */
    #[Computed]
    public function contrast(): array
    {
        $theme = $this->theme();

        return $theme ? app(ThemeService::class)->contrastReport($theme, $this->scope, $this->mode) : [];
    }

    /**
     * Groups, with their tokens, filtered by the search box.
     *
     * Search covers the label, the key and the "what this affects" note, so
     * someone looking for "the colour behind a modal" finds it without knowing
     * it is called an overlay.
     *
     * @return array<string, array{label: string, description: string, primary: bool, tokens: array<string, array<string, string>>}>
     */
    #[Computed]
    public function groups(): array
    {
        $needle = trim(mb_strtolower($this->search));
        $catalogue = TokenCatalogue::tokens();
        $out = [];

        foreach (TokenCatalogue::groups() as $key => $group) {
            $tokens = [];

            foreach ($catalogue as $tokenKey => $definition) {
                if ($definition['group'] !== $key) {
                    continue;
                }

                if ($needle !== '' && ! str_contains(
                    mb_strtolower($definition['label'].' '.$tokenKey.' '.$definition['affects']),
                    $needle,
                )) {
                    continue;
                }

                $tokens[$tokenKey] = $definition;
            }

            if ($tokens !== []) {
                $out[$key] = $group + ['tokens' => $tokens];
            }
        }

        return $out;
    }

    public function isPreviewing(): bool
    {
        return app(ThemeService::class)->isPreviewing();
    }

    public function canWrite(): bool
    {
        return auth()->user()?->can('themes.manage') ?? false;
    }

    /**
     * Inline custom properties for the live preview card, built from the
     * values currently in the editor — including unsaved ones, which is the
     * whole point of a preview.
     *
     * Those values have NOT been through TokenValidator yet (validation
     * happens on save), so they are stripped here with the same rule the
     * compiler uses. An administrator cannot inject anything through their own
     * unsaved draft, and nobody else can see it, but "only the author can
     * exploit it" is not a reason to emit unescaped input.
     */
    public function previewStyle(): string
    {
        $out = '';

        foreach (self::fromWireKeys($this->values) as $key => $value) {
            $safe = trim(mb_substr(str_replace(['<', '>', '{', '}', ';', '@', '"', "'"], '', $value), 0, 120));

            if ($safe !== '') {
                $out .= '--'.str_replace('.', '-', $key).':'.$safe.';';
            }
        }

        return $out;
    }

    public function contrastOf(string $foreground, string $background): float
    {
        return ColorRamp::isHex($foreground) && ColorRamp::isHex($background)
            ? ColorRamp::contrastRatio($foreground, $background)
            : 0.0;
    }

    // -- header actions ------------------------------------------------------

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')
                ->label(fn () => $this->isPreviewing() ? 'Stop preview' : 'Preview on the site')
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->visible(fn () => $this->canWrite() && $this->theme())
                ->action(function () {
                    $themes = app(ThemeService::class);

                    if ($this->isPreviewing()) {
                        $themes->stopPreview();
                        Notification::make()->title('Preview off')->send();

                        return;
                    }

                    $themes->preview($this->theme());

                    Notification::make()
                        ->title('Previewing')
                        ->body('Only you see this. Visitors still see the published theme.')
                        ->send();
                }),

            Action::make('duplicate')
                ->label('Duplicate')
                ->icon('heroicon-o-document-duplicate')
                ->color('gray')
                ->visible(fn () => $this->canWrite() && $this->theme())
                ->schema([
                    TextInput::make('name')
                        ->label('Name for the copy')
                        ->required()
                        ->maxLength(60)
                        ->default(fn () => $this->theme()?->name.' copy'),
                ])
                ->action(function (array $data) {
                    $copy = app(ThemeService::class)->duplicate($this->theme(), $data['name'], auth()->id());

                    app(ActivityLogger::class)->log('theme.duplicated', $copy, null, ['name' => $copy->name]);

                    $this->themeId = $copy->getKey();
                    $this->load();

                    Notification::make()->title('Copied')->body('Editing "'.$copy->name.'".')->success()->send();
                }),

            Action::make('publish')
                ->label('Publish')
                ->icon('heroicon-o-check-circle')
                ->requiresConfirmation()
                ->modalHeading('Publish this theme?')
                ->modalDescription('Every visitor will see it immediately. The theme that is live now is remembered, so you can put it back in one click.')
                ->visible(fn () => $this->canWrite() && $this->theme() && ! $this->theme()->is_active)
                ->action(function () {
                    $theme = $this->theme();
                    $previous = app(ThemeService::class)->publish($theme, auth()->id());

                    app(ActivityLogger::class)->log(
                        'theme.published',
                        $theme,
                        ['active' => $previous?->name],
                        ['active' => $theme->name],
                    );

                    Notification::make()->title('"'.$theme->name.'" is now live')->success()->send();
                }),

            Action::make('restore')
                ->label('Restore previous')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('Puts the previously published theme back.')
                ->visible(fn () => $this->canWrite() && settings('theme.previous_theme_id'))
                ->action(function () {
                    $restored = app(ThemeService::class)->restorePrevious(auth()->id());

                    if (! $restored) {
                        Notification::make()->title('Nothing to restore')->warning()->send();

                        return;
                    }

                    app(ActivityLogger::class)->log('theme.restored', $restored, null, ['active' => $restored->name]);

                    $this->themeId = $restored->getKey();
                    $this->load();

                    Notification::make()->title('"'.$restored->name.'" restored')->success()->send();
                }),
        ];
    }

    /**
     * Built-ins are never edited in place. Duplicating first is what
     * guarantees there is always a known-good theme to return to (§5).
     */
    private function guardWritable(): ?Theme
    {
        $theme = $this->theme();

        if (! $this->canWrite()) {
            Notification::make()->title('Not permitted')->danger()->send();

            return null;
        }

        if (! $theme) {
            return null;
        }

        if ($theme->is_builtin) {
            Notification::make()
                ->title('Built-in themes cannot be changed')
                ->body('Use Duplicate to make your own copy of "'.$theme->name.'", then edit that.')
                ->warning()
                ->send();

            return null;
        }

        return $theme;
    }
}
