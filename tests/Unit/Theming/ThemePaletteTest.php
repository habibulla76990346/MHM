<?php

namespace Tests\Unit\Theming;

use App\Domains\Theming\Support\BuiltInThemes;
use App\Domains\Theming\Support\ColorRamp;
use App\Domains\Theming\Support\ThemePalette;
use App\Domains\Theming\Support\TokenCatalogue;
use PHPUnit\Framework\TestCase;

class ThemePaletteTest extends TestCase
{
    /**
     * The catalogue is the contract: a token listed there but not derived
     * would be editable in the Admin Panel and have no default, so a new theme
     * would silently fall back to whatever the last one left behind.
     */
    public function test_derivation_covers_every_token_in_the_catalogue(): void
    {
        $derived = ThemePalette::derive(BuiltInThemes::all()['light']['light']);

        $this->assertSame(
            array_keys(TokenCatalogue::tokens()),
            array_keys($derived),
            'The catalogue and the derivation engine have drifted apart.',
        );
    }

    /**
     * Owner decision D-07 and the Phase 2 instruction that branding must
     * propagate "without template-specific overrides": the Admin Panel is the
     * same palette at a different density, never a second set of colours.
     */
    public function test_both_scopes_derive_identical_colours_from_one_palette(): void
    {
        $palette = BuiltInThemes::all()['ocean']['light'];

        $customer = ThemePalette::derive($palette, TokenCatalogue::SCOPE_CUSTOMER, 'light');
        $admin = ThemePalette::derive($palette, TokenCatalogue::SCOPE_ADMIN, 'light');

        foreach (TokenCatalogue::colorTokens() as $key) {
            $this->assertSame($customer[$key], $admin[$key],
                "The admin panel diverged from the customer palette on {$key}.");
        }

        // ... and the difference that IS allowed is density.
        $this->assertNotSame($customer['space.scale'], $admin['space.scale']);
        $this->assertNotSame($customer['radius.md'], $admin['radius.md']);
    }

    /**
     * The editor must not be able to produce an unreadable site, so the
     * built-in themes are not merely checked for contrast — they are DERIVED
     * to meet it.
     */
    public function test_every_built_in_theme_meets_wcag_aa_on_every_checked_pair(): void
    {
        $failures = [];

        foreach (BuiltInThemes::all() as $slug => $theme) {
            foreach ([TokenCatalogue::MODE_LIGHT, TokenCatalogue::MODE_DARK] as $mode) {
                foreach ([TokenCatalogue::SCOPE_CUSTOMER, TokenCatalogue::SCOPE_ADMIN] as $scope) {
                    $tokens = ThemePalette::derive($theme[$mode], $scope, $mode);

                    foreach (TokenCatalogue::contrastPairs() as [$fg, $bg, $label]) {
                        $ratio = ColorRamp::contrastRatio($tokens[$fg], $tokens[$bg]);

                        if ($ratio < 4.5) {
                            $failures[] = sprintf('%s/%s/%s — %s: %.2f', $slug, $mode, $scope, $label, $ratio);
                        }
                    }
                }
            }
        }

        $this->assertSame([], $failures, implode("\n", $failures));
    }

    /**
     * An administrator can author any palette at all. The guarantee has to
     * hold for theirs too, not only for the eight that ship.
     */
    public function test_a_deliberately_hostile_palette_still_derives_readable_text(): void
    {
        // Mid-lightness everything: neither black nor white is an easy win,
        // and the authored muted and accent are far below AA as given.
        $tokens = ThemePalette::derive([
            'primary' => '#7a7a7a', 'accent' => '#9ad0ff', 'background' => '#dddddd',
            'surface' => '#d5d5d5', 'text' => '#3a3a3a', 'muted' => '#b0b0b0', 'border' => '#c4c4c4',
        ]);

        foreach (TokenCatalogue::contrastPairs() as [$fg, $bg, $label]) {
            $this->assertGreaterThanOrEqual(4.5, ColorRamp::contrastRatio($tokens[$fg], $tokens[$bg]), $label);
        }
    }

    public function test_status_colours_ignore_the_brand_palette(): void
    {
        $green = ThemePalette::derive(['primary' => '#15803d', 'accent' => '#22c55e'] + BuiltInThemes::all()['light']['light']);
        $blue = ThemePalette::derive(BuiltInThemes::all()['professional']['light']);

        // An error must read as an error on a green-branded site.
        $this->assertSame($green['color.danger'], $blue['color.danger']);
        $this->assertNotSame($green['color.danger'], $green['color.primary']);
    }

    public function test_a_missing_or_invalid_palette_entry_falls_back_instead_of_breaking(): void
    {
        $tokens = ThemePalette::derive(['primary' => 'not-a-colour']);

        foreach (TokenCatalogue::colorTokens() as $key) {
            $this->assertTrue(ColorRamp::isHex($tokens[$key]), "{$key} was not a colour: {$tokens[$key]}");
        }
    }

    public function test_dark_palettes_are_detected_from_the_colours_not_the_label(): void
    {
        // Labelled light, authored dark. The derivation should follow the
        // colours, so its shadows and surfaces come out dark-appropriate.
        $tokens = ThemePalette::derive(BuiltInThemes::all()['midnight']['dark'], TokenCatalogue::SCOPE_CUSTOMER, 'light');

        $this->assertGreaterThan(
            ColorRamp::hexToOklch($tokens['color.background'])[0],
            ColorRamp::hexToOklch($tokens['color.surface-raised'])[0],
            'On a dark background a raised surface must be LIGHTER than the page, not darker.',
        );
    }
}
