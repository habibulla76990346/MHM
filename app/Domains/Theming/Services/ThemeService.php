<?php

namespace App\Domains\Theming\Services;

use App\Domains\Theming\Models\Theme;
use App\Domains\Theming\Models\ThemeToken;
use App\Domains\Theming\Support\ColorRamp;
use App\Domains\Theming\Support\ThemePalette;
use App\Domains\Theming\Support\TokenCatalogue;
use App\Domains\Theming\Support\TokenValidator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Compiles a theme into CSS custom properties and serves it to both panels.
 *
 * D-07: ONE engine, TWO token sets. The customer application and the Admin
 * Panel are the same system with different scopes, not two theme systems —
 * which is why a branding change propagates to both without any
 * template-specific override.
 *
 * PERFORMANCE: compiled CSS is cached per theme/scope/mode. Rendering a page
 * costs one cache read, not ~95 database rows.
 */
class ThemeService
{
    public const PREVIEW_SESSION_KEY = 'aziv.theme_preview';

    private const CACHE_PREFIX = 'aziv:theme:css:';

    private const ACTIVE_CACHE_KEY = 'aziv:theme:active';

    private ?Theme $resolved = null;

    /** Set once the theme tables have proved unreadable, so each request asks at most once. */
    private bool $unavailable = false;

    public function __construct(private readonly CssSanitiser $sanitiser) {}

    /**
     * The theme to render.
     *
     * Preview is deliberately session-scoped: an administrator sees the draft
     * while every visitor continues to see the published theme, so a
     * half-finished theme is never shown to a customer (blueprint §5).
     */
    public function active(): ?Theme
    {
        if ($this->resolved) {
            return $this->resolved;
        }

        if ($this->unavailable) {
            return null;
        }

        return $this->attempt(function (): ?Theme {
            $previewId = Session::get(self::PREVIEW_SESSION_KEY);

            if ($previewId && auth()->check() && auth()->user()->can('themes.manage')) {
                $preview = Theme::find($previewId);
                if ($preview) {
                    return $this->resolved = $preview;
                }
            }

            // Cache the ID, never the model.
            //
            // rememberForever() on an Eloquent instance serialises the whole
            // object into the cache store. With the file or database driver
            // that payload outlives the code that wrote it, and a deploy that
            // touches the model class brings back __PHP_Incomplete_Class — on
            // EVERY page, login included, with no way in to fix it. A scalar
            // id plus one primary-key lookup cannot fail that way.
            //
            // The array driver used in tests never serialises, so this is
            // exactly the class of fault a test suite alone will not find.
            $id = Cache::rememberForever(
                self::ACTIVE_CACHE_KEY,
                fn () => Theme::where('is_active', true)->value('id') ?? 0,
            );

            return $this->resolved = $id ? Theme::find($id) : null;
        });
    }

    /**
     * Read the theme, or give up quietly.
     *
     * Swallowing an exception is normally how a fault becomes a mystery, and
     * it is the right call in exactly this one place. Theming is decoration:
     * without it the interface falls back to the defaults compiled into
     * tokens.css and remains completely usable. A theme lookup that can take
     * the request down means a database that is unreachable, or a release
     * whose migrations have not run yet, locks the administrator out of the
     * Admin Panel — and System Health, the screen that would tell them WHY, is
     * inside the Admin Panel.
     *
     * Nothing is hidden by this: the database and migration checks in the
     * diagnostics layer report the underlying fault with its exact technical
     * reason (Owner Addendum G).
     *
     * @template T
     *
     * @param  callable(): T  $read
     * @return T|null
     */
    private function attempt(callable $read): mixed
    {
        try {
            return $read();
        } catch (\Throwable) {
            $this->unavailable = true;

            return null;
        }
    }

    public function isPreviewing(): bool
    {
        return Session::has(self::PREVIEW_SESSION_KEY);
    }

    public function preview(Theme $theme): void
    {
        Session::put(self::PREVIEW_SESSION_KEY, $theme->getKey());
        $this->resolved = null;
    }

    public function stopPreview(): void
    {
        Session::forget(self::PREVIEW_SESSION_KEY);
        $this->resolved = null;
    }

    /**
     * Publish a theme, remembering which was active so it can be restored.
     * One-click restore is what makes editing a live site's appearance safe.
     */
    public function publish(Theme $theme, ?int $actorId = null): ?Theme
    {
        $previous = Theme::where('is_active', true)->first();

        Theme::where('is_active', true)->update(['is_active' => false]);

        $theme->forceFill([
            'is_active' => true,
            'published_at' => now(),
            'updated_by' => $actorId,
        ])->save();

        // increment(), never `version + 1`: a model created without an explicit
        // version has NULL in memory while the database holds 1, so the
        // arithmetic writes 1 over 1 — the version never moves, and the
        // compiled-CSS cache key never changes, so the old stylesheet is served
        // forever. increment() reads the stored value and updates the instance.
        $theme->increment('version');

        if ($previous && ! $previous->is($theme)) {
            settings()->set('theme.previous_theme_id', (string) $previous->getKey(), $actorId);
        }

        $this->flush();

        return $previous;
    }

    public function restorePrevious(?int $actorId = null): ?Theme
    {
        $previousId = settings('theme.previous_theme_id');

        if (! $previousId) {
            return null;
        }

        $previous = Theme::find((int) $previousId);

        if (! $previous) {
            return null;
        }

        $this->publish($previous, $actorId);

        return $previous;
    }

    /**
     * The <style> block for a scope. Contains BOTH modes: light on :root,
     * dark behind the three selectors that cover every viewer state — an
     * explicit choice either way, and the un-stamped system default.
     */
    public function compiledCss(string $scope = TokenCatalogue::SCOPE_CUSTOMER): string
    {
        $theme = $this->active();

        if (! $theme) {
            return '';   // fall back to the defaults compiled into app.css
        }

        $key = self::CACHE_PREFIX.$theme->getKey().':'.$scope.':v'.$theme->version;

        return $this->attempt(fn () => Cache::rememberForever($key, function () use ($theme, $scope) {
            $light = $this->declarations($theme, $scope, TokenCatalogue::MODE_LIGHT);
            $dark = $this->declarations($theme, $scope, TokenCatalogue::MODE_DARK);

            $css = ":root{{$light}}";

            if ($theme->supports_dark && $dark !== '') {
                // The same selectors tokens.css uses, for the same reason: the
                // customer app stamps data-theme, Filament toggles a `dark`
                // class, and a viewer who has chosen neither is matched by the
                // media query. Missing one of the three leaves a panel showing
                // light tokens on a dark chrome.
                $css .= "@media (prefers-color-scheme:dark){:root:not([data-theme='light']):not(.light){{$dark}}}";
                $css .= ":root[data-theme='dark'],:root.dark{{$dark}}";
            }

            if ($scope === TokenCatalogue::SCOPE_CUSTOMER && $theme->custom_css) {
                $css .= $this->sanitiser->sanitise($theme->custom_css);
            }

            return $css;
        })) ?? '';
    }

    /**
     * Persist edited token values for one scope and mode.
     *
     * Returns the before/after pair for the audit trail: every admin write
     * authorises, validates and audits, or it does not ship.
     *
     * @param  array<string, string>  $values  token_key => value
     * @return array{before: array<string,string>, after: array<string,string>}
     *
     * @throws ValidationException when any value is not a safe shape for its type
     */
    public function saveTokens(Theme $theme, string $scope, string $mode, array $values, ?int $actorId = null): array
    {
        $errors = TokenValidator::validate($values);

        if ($errors !== []) {
            throw ValidationException::withMessages(
                // Dots in a Laravel message key read as nested-array access, so
                // token keys are flattened for the error bag. (Same trap the
                // settings service hit — see CLAUDE.md.)
                collect($errors)->mapWithKeys(fn ($m, $k) => ['values.'.str_replace('.', '_', $k) => $m])->all(),
            );
        }

        $before = $theme->tokenMap($scope, $mode);
        $changed = [];

        DB::transaction(function () use ($theme, $scope, $mode, $values, $before, &$changed) {
            foreach ($values as $key => $value) {
                $value = trim((string) $value);

                if (($before[$key] ?? null) === $value) {
                    continue;
                }

                $theme->tokens()->updateOrCreate(
                    ['scope' => $scope, 'mode' => $mode, 'token_key' => $key],
                    ['token_value' => $value, 'token_group' => TokenCatalogue::tokens()[$key]['group']],
                );

                $changed[$key] = $value;
            }

            if ($changed !== []) {
                // The compiled-CSS cache key carries the version, so bumping it
                // is what makes the change visible. Forgetting this would serve
                // the old stylesheet until the cache was cleared by hand.
                $theme->increment('version');
            }
        });

        $this->flush();

        return [
            'before' => array_intersect_key($before, $changed),
            'after' => $changed,
        ];
    }

    /**
     * Re-derive every token in the theme from a palette.
     *
     * This is the editor's main affordance: change seven colours, get a
     * coherent theme. It overwrites BOTH scopes and BOTH modes, because a
     * palette that applied to only one of them is how the Admin Panel drifts
     * away from the customer application.
     *
     * @param  array{light: array<string,string>, dark: array<string,string>}  $palettes
     */
    public function applyPalette(Theme $theme, array $palettes, ?int $actorId = null): void
    {
        DB::transaction(function () use ($theme, $palettes, $actorId) {
            foreach ([TokenCatalogue::SCOPE_CUSTOMER, TokenCatalogue::SCOPE_ADMIN] as $scope) {
                foreach ([TokenCatalogue::MODE_LIGHT, TokenCatalogue::MODE_DARK] as $mode) {
                    foreach (ThemePalette::derive($palettes[$mode] ?? [], $scope, $mode) as $key => $value) {
                        $theme->tokens()->updateOrCreate(
                            ['scope' => $scope, 'mode' => $mode, 'token_key' => $key],
                            ['token_value' => $value, 'token_group' => TokenCatalogue::tokens()[$key]['group']],
                        );
                    }
                }
            }

            $theme->forceFill(['updated_by' => $actorId])->save();
            $theme->increment('version');
        });

        $this->flush();
    }

    /**
     * Copy a theme so a built-in can be used as a starting point.
     * Built-ins are never editable in place — that is what guarantees there is
     * always a known-good theme to return to.
     */
    public function duplicate(Theme $theme, string $name, ?int $actorId = null): Theme
    {
        return DB::transaction(function () use ($theme, $name, $actorId) {
            $copy = Theme::create([
                'name' => $name,
                'slug' => $this->uniqueSlug($name),
                'description' => 'Copied from '.$theme->name.'.',
                'is_builtin' => false,
                'is_active' => false,
                'supports_dark' => $theme->supports_dark,
                'base_theme_id' => $theme->getKey(),
                'custom_css' => $theme->custom_css,
                'version' => 1,
                'updated_by' => $actorId,
            ]);

            $now = now();

            $rows = $theme->tokens()->get()->map(fn (ThemeToken $t) => [
                'theme_id' => $copy->getKey(),
                'scope' => $t->scope,
                'mode' => $t->mode,
                'token_group' => $t->token_group,
                'token_key' => $t->token_key,
                'token_value' => $t->token_value,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('theme_tokens')->insert($chunk);
            }

            return $copy;
        });
    }

    /**
     * Custom CSS, sanitised on the way in.
     *
     * Storing the raw value and sanitising only at render would leave the
     * dangerous text sitting in the database, one careless `{!! !!}` away from
     * being used. What is stored is what will be served.
     *
     * @return array<int, string> what was removed, so the administrator is told
     */
    public function saveCustomCss(Theme $theme, ?string $css, ?int $actorId = null): array
    {
        $removed = $this->sanitiser->report($css);

        $theme->forceFill([
            'custom_css' => $this->sanitiser->sanitise($css),
            'updated_by' => $actorId,
        ])->save();

        $theme->increment('version');

        $this->flush();

        return $removed;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'theme';
        $slug = $base;
        $n = 2;

        while (Theme::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }

    /**
     * The browser-chrome colour for <meta name="theme-color">.
     *
     * On Android and on an installed PWA this paints the system UI around the
     * page, so a hard-coded value shows the OLD brand colour as a border around
     * a re-themed app. It is a theme token like any other.
     */
    public function themeColor(string $mode = TokenCatalogue::MODE_LIGHT, string $scope = TokenCatalogue::SCOPE_CUSTOMER): string
    {
        $theme = $this->active();

        $value = $theme?->tokenMap($scope, $mode)['color.background'] ?? null;

        if (is_string($value) && ColorRamp::isHex($value)) {
            return '#'.ltrim($value, '#');
        }

        // Matches the compiled-in defaults in tokens.css.
        return $mode === TokenCatalogue::MODE_DARK ? '#0b1120' : '#f8fafc';
    }

    /** @return array<string, array<int, string>> Filament colour ramps for this theme */
    public function filamentColors(): array
    {
        $theme = $this->active();

        if (! $theme) {
            return [];
        }

        return Cache::rememberForever(
            self::CACHE_PREFIX.'filament:'.$theme->getKey().':v'.$theme->version,
            function () use ($theme) {
                $tokens = $theme->tokenMap(TokenCatalogue::SCOPE_ADMIN, TokenCatalogue::MODE_LIGHT);

                $sources = [
                    'primary' => $tokens['color.primary'] ?? null,
                    'danger' => $tokens['color.danger'] ?? null,
                    'success' => $tokens['color.success'] ?? null,
                    'warning' => $tokens['color.warning'] ?? null,
                    'info' => $tokens['color.info'] ?? null,
                    'gray' => $tokens['color.text-muted'] ?? null,
                ];

                $colors = [];

                foreach ($sources as $name => $hex) {
                    if ($hex && ColorRamp::isHex($hex)) {
                        // Filament wants a full 50–950 ramp, not one hex —
                        // see ColorRamp for why it is generated in OKLCH.
                        $colors[$name] = ColorRamp::fromHex($hex);
                    }
                }

                return $colors;
            },
        );
    }

    /** @return array<int, array{pair: string, ratio: float, passes: bool}> */
    public function contrastReport(Theme $theme, string $scope, string $mode): array
    {
        $tokens = $theme->tokenMap($scope, $mode);
        $report = [];

        foreach (TokenCatalogue::contrastPairs() as [$fgKey, $bgKey, $label]) {
            $fg = $tokens[$fgKey] ?? null;
            $bg = $tokens[$bgKey] ?? null;

            if (! $fg || ! $bg || ! ColorRamp::isHex($fg) || ! ColorRamp::isHex($bg)) {
                continue;
            }

            $ratio = ColorRamp::contrastRatio($fg, $bg);

            $report[] = [
                'pair' => $label,
                'foreground' => $fgKey,
                'background' => $bgKey,
                'ratio' => $ratio,
                // WCAG AA for normal text.
                'passes' => $ratio >= 4.5,
            ];
        }

        return $report;
    }

    public function flush(): void
    {
        $this->resolved = null;
        Cache::forget(self::ACTIVE_CACHE_KEY);

        // Version is part of the key, so bumping it on publish already
        // invalidates compiled CSS. This clears the resolved-theme pointer.
        foreach ($this->attempt(fn () => Theme::pluck('id')) ?? [] as $id) {
            foreach ([TokenCatalogue::SCOPE_CUSTOMER, TokenCatalogue::SCOPE_ADMIN] as $scope) {
                for ($v = 1; $v <= 3; $v++) {
                    Cache::forget(self::CACHE_PREFIX.$id.':'.$scope.':v'.$v);
                }
            }
        }
    }

    private function declarations(Theme $theme, string $scope, string $mode): string
    {
        $tokens = $theme->tokenMap($scope, $mode);

        if ($tokens === []) {
            return '';
        }

        $out = '';

        foreach ($tokens as $key => $value) {
            $property = '--'.str_replace('.', '-', $key);
            $out .= $property.':'.$this->safeValue($value).';';
        }

        return $out;
    }

    /**
     * A token value reaches a stylesheet, so it must not be able to close the
     * declaration and start something else. Tokens come from the Admin Panel,
     * but "an administrator typed it" is not a reason to skip escaping.
     */
    private function safeValue(string $value): string
    {
        $value = str_replace(['<', '>', '{', '}', ';', '@'], '', $value);

        return trim(mb_substr($value, 0, 200));
    }
}
