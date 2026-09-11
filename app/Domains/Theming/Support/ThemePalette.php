<?php

namespace App\Domains\Theming\Support;

/**
 * Expands a small palette into the complete token set.
 *
 * WHY THIS EXISTS (owner decision D-07)
 * -------------------------------------
 * The Admin Panel must control "the complete practical design system" — around
 * ninety-five values per mode. Asking an administrator to choose ninety-five
 * colours twice over, for two scopes, is a theme editor nobody can finish.
 *
 * So a theme is authored as roughly seven decisions — primary, accent,
 * background, surface, text, muted, border — and everything else is DERIVED
 * from those here. The editor still exposes every token for anyone who wants
 * to override one; derivation just means the defaults are already coherent.
 *
 * This is also the mechanism behind the owner's Phase 2 instruction that
 * "branding changes propagate consistently without template-specific
 * overrides": the customer application and the Admin Panel are derived from
 * the SAME palette by the same function. There is no second set of colour
 * decisions for the admin scope — only density differences (tighter gutters,
 * smaller radii), because an admin table is not a marketing page.
 *
 * Every derivation is perceptual (OKLCH, via ColorRamp) rather than arithmetic
 * on hex, so "slightly lighter" means slightly lighter to the eye at every
 * hue, and hue never drifts.
 */
class ThemePalette
{
    /** The keys a theme author actually supplies. */
    public const PALETTE_KEYS = ['primary', 'accent', 'background', 'surface', 'text', 'muted', 'border'];

    /**
     * Fixed hues for the status colours, in OKLCH degrees.
     *
     * Status colour is MEANING, not branding: an error must read as an error
     * even on a green-branded site, so these hues are not derived from the
     * palette. Only their lightness and chroma adapt to the mode, which is what
     * keeps them legible on a dark background without becoming neon.
     */
    private const STATUS_HUES = [
        'danger' => 27.0,
        'warning' => 70.0,
        'success' => 145.0,
        'info' => 255.0,
    ];

    /**
     * @param  array<string, string>  $palette
     * @return array<string, string> token_key => value, covering every token in the catalogue
     */
    public static function derive(array $palette, string $scope = TokenCatalogue::SCOPE_CUSTOMER, string $mode = TokenCatalogue::MODE_LIGHT): array
    {
        $p = self::normalise($palette);

        $primary = $p['primary'];
        $accent = $p['accent'];
        $background = $p['background'];
        $surface = $p['surface'];
        $text = $p['text'];
        $muted = $p['muted'];
        $border = $p['border'];

        // Trust the colours, not the label. A theme whose "light" mode was
        // given a near-black background should still derive sensibly, so every
        // decision below keys off measured lightness.
        $isDark = ColorRamp::hexToOklch($background)[0] < 0.5;

        // The two inks available to this theme, lightest and darkest. Text that
        // sits on a derived background is chosen between them, which is why the
        // built-in themes pass the contrast report by construction rather than
        // by being checked afterwards.
        $inkLight = $isDark ? $text : $surface;
        $inkDark = $isDark ? $background : $text;

        $readable = function (string $bg) use ($inkLight, $inkDark): string {
            $ink = ColorRamp::readableOn($bg, $inkLight, $inkDark);

            // A palette can be chosen so that NEITHER of its inks is legible on
            // a derived surface — a mid-lightness primary is the usual cause.
            // Falling back to plain black or white is less pretty than the
            // theme's own ink and is never unreadable, which is the trade the
            // accessibility threshold exists to make.
            return ColorRamp::contrastRatio($ink, $bg) >= 4.5
                ? $ink
                : ColorRamp::readableOn($bg, '#ffffff', '#000000');
        };

        // Interactive states move a colour AWAY from wherever it already sits:
        // a dark button lightens, a light button darkens. A fixed "always
        // darker" rule crushes dark primaries into black.
        $shift = function (string $hex, float $amount): string {
            $isLight = ColorRamp::hexToOklch($hex)[0] >= 0.5;

            return ColorRamp::adjustLightness($hex, $isLight ? -$amount : $amount);
        };

        $status = self::statusColours($isDark);
        $statusBg = [];
        foreach ($status as $name => $hex) {
            // A tint of the status colour over the page, not the colour itself:
            // a solid red panel behind an error message is unreadable.
            $statusBg[$name] = ColorRamp::mix($background, $hex, $isDark ? 0.18 : 0.12);
        }

        $surfaceRaised = $isDark
            ? ColorRamp::adjustLightness($surface, 0.045)
            : ColorRamp::adjustLightness($surface, ColorRamp::hexToOklch($surface)[0] > 0.97 ? -0.012 : 0.02);

        $hover = ColorRamp::mix($surface, $text, 0.07);
        $active = ColorRamp::mix($surface, $primary, 0.14);
        $secondaryBg = ColorRamp::mix($surface, $text, 0.09);
        $badgeBg = ColorRamp::mix($surface, $primary, 0.18);
        $assistantBubble = ColorRamp::mix($surface, $text, 0.06);
        $codeBg = $isDark
            ? ColorRamp::adjustLightness($background, -0.03)
            : ColorRamp::mix($surface, $text, 0.055);
        $inputBg = $isDark ? ColorRamp::mix($surface, $background, 0.55) : $surface;

        // The accent exists to catch the eye; a link has to be READ. Vivid
        // accents land around 2:1 on a pale page, so the link is the accent
        // walked to AA — same hue, enough contrast.
        $link = ColorRamp::readableAgainst($accent, $background);
        // Hover always moves toward more contrast, so it cannot undo that.
        $linkHover = ColorRamp::adjustLightness($link, $isDark ? 0.08 : -0.08);

        $tokens = [
            // --- Brand ---------------------------------------------------
            'color.primary' => $primary,
            'color.primary-hover' => $shift($primary, 0.05),
            'color.primary-active' => $shift($primary, 0.09),
            'color.secondary' => ColorRamp::mix($muted, $primary, 0.3),
            'color.accent' => $accent,

            // --- Surfaces ------------------------------------------------
            'color.background' => $background,
            'color.surface' => $surface,
            'color.surface-raised' => $surfaceRaised,
            'color.card' => $surface,
            // Eight-digit hex: the dimmer behind a modal needs alpha, and the
            // token pipeline treats it as an ordinary colour value.
            'color.overlay' => ($isDark ? '#000000' : '#0f172a').($isDark ? 'cc' : '99'),

            // --- Text ----------------------------------------------------
            'color.text' => $text,
            // Muted text is still text: the catalogue checks it against the card
            // surface, so it is corrected there rather than left to the author.
            'color.text-muted' => ColorRamp::readableAgainst($muted, $surface),
            'color.text-inverse' => $readable($primary),
            'color.heading' => ColorRamp::adjustLightness($text, $isDark ? 0.04 : -0.04),

            // --- Lines ---------------------------------------------------
            'color.border' => $border,
            'color.border-strong' => ColorRamp::mix($border, $text, 0.28),
            'color.divider' => ColorRamp::mix($border, $background, 0.45),

            // --- State ---------------------------------------------------
            'color.success' => $status['success'],
            'color.success-bg' => $statusBg['success'],
            'color.warning' => $status['warning'],
            'color.warning-bg' => $statusBg['warning'],
            'color.danger' => $status['danger'],
            'color.danger-bg' => $statusBg['danger'],
            'color.info' => $status['info'],
            'color.info-bg' => $statusBg['info'],

            // --- Interactive ---------------------------------------------
            'color.link' => $link,
            'color.link-hover' => $linkHover,
            'color.hover' => $hover,
            'color.active' => $active,
            'color.focus-ring' => $primary,
            'color.disabled' => ColorRamp::mix($muted, $background, 0.5),

            // --- Components ----------------------------------------------
            'color.sidebar-bg' => $surface,
            'color.sidebar-text' => $text,
            'color.sidebar-active' => $active,
            'color.navbar-bg' => $surface,
            'color.navbar-text' => $text,
            'color.button-secondary-bg' => $secondaryBg,
            'color.button-secondary-text' => $readable($secondaryBg),
            'color.input-bg' => $inputBg,
            'color.input-border' => $border,
            'color.input-text' => $text,
            'color.input-placeholder' => $muted,
            'color.table-header-bg' => ColorRamp::mix($surface, $text, 0.05),
            'color.table-row-hover' => $hover,
            'color.table-stripe' => ColorRamp::mix($surface, $text, 0.03),
            'color.badge-bg' => $badgeBg,
            'color.badge-text' => $readable($badgeBg),
            'color.modal-bg' => $surfaceRaised,
            'color.chat-bubble-user' => $primary,
            'color.chat-bubble-user-text' => $readable($primary),
            'color.chat-bubble-assistant' => $assistantBubble,
            'color.chat-bubble-assistant-text' => $readable($assistantBubble),
            'color.code-bg' => $codeBg,
            'color.code-text' => $readable($codeBg),
            'color.code-accent' => $accent,
        ];

        return $tokens + self::metrics($scope, $isDark);
    }

    /**
     * Everything that is not a colour. These do not vary with the palette, but
     * they do vary with scope: the Admin Panel is a working tool and packs more
     * into a screen than the customer application does.
     *
     * @return array<string, string>
     */
    private static function metrics(string $scope, bool $isDark): array
    {
        $admin = $scope === TokenCatalogue::SCOPE_ADMIN;

        // Shadows read as almost nothing on a dark surface unless they are
        // deepened, because there is less luminance left to subtract.
        $alpha = $isDark ? [0.5, 0.55, 0.65] : [0.06, 0.1, 0.16];

        return [
            'font.sans' => "'Inter var', Inter, ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif",
            'font.mono' => "ui-monospace, SFMono-Regular, 'JetBrains Mono', Menlo, Consolas, monospace",
            'font.heading-weight' => '650',
            'font.body-weight' => '400',
            'font.scale' => $admin ? '0.96' : '1',

            'radius.sm' => $admin ? '0.3rem' : '0.375rem',
            'radius.md' => $admin ? '0.5rem' : '0.625rem',
            'radius.lg' => $admin ? '0.75rem' : '1rem',
            'shadow.sm' => "0 1px 2px rgb(0 0 0 / {$alpha[0]})",
            'shadow.md' => "0 4px 12px rgb(0 0 0 / {$alpha[1]})",
            'shadow.lg' => "0 16px 40px rgb(0 0 0 / {$alpha[2]})",

            // The admin scope differs from the customer scope ONLY in density.
            // Every colour comes from the same palette, which is what makes a
            // branding change reach both panels without a per-template override.
            'space.scale' => $admin ? '0.88' : '1',
            'space.gutter-min' => $admin ? '0.875rem' : '1rem',
            'space.section-min' => $admin ? '1.5rem' : '2rem',
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function statusColours(bool $isDark): array
    {
        // On a dark background a 0.55-lightness red disappears; on a light one
        // a 0.72-lightness red looks like a pastel. Same hue, different level.
        $lightness = $isDark ? 0.74 : 0.56;
        $chroma = $isDark ? 0.15 : 0.17;

        $out = [];

        foreach (self::STATUS_HUES as $name => $hue) {
            // No per-hue tuning here: ColorRamp reduces chroma to whatever sRGB
            // can actually hold at this lightness, so the hue arrives intact.
            $out[$name] = ColorRamp::oklchToHex($lightness, $chroma, $hue);
        }

        return $out;
    }

    /**
     * A palette arrives from a seeder or from the Admin Panel. Anything missing
     * or not a colour falls back rather than producing a broken stylesheet —
     * a theme editor must never be able to render the site unusable.
     *
     * @param  array<string, string>  $palette
     * @return array<string, string>
     */
    private static function normalise(array $palette): array
    {
        $fallback = [
            'primary' => '#1f2937', 'accent' => '#6366f1', 'background' => '#ffffff',
            'surface' => '#ffffff', 'text' => '#111827', 'muted' => '#6b7280', 'border' => '#e5e7eb',
        ];

        $out = [];

        foreach (self::PALETTE_KEYS as $key) {
            $value = $palette[$key] ?? null;
            $out[$key] = is_string($value) && ColorRamp::isHex($value)
                ? '#'.ltrim(trim($value), '#')
                : $fallback[$key];
        }

        return $out;
    }
}
