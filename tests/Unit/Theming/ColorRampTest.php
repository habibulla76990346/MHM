<?php

namespace Tests\Unit\Theming;

use App\Domains\Theming\Support\ColorRamp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ColorRampTest extends TestCase
{
    public static function colours(): array
    {
        return [
            'brand blue' => ['#2563eb'],
            'near black' => ['#0f172a'],
            'white' => ['#ffffff'],
            'teal' => ['#14b8a6'],
            'deep red' => ['#b91c1c'],
            'bright yellow' => ['#fde047'],
            'cyan' => ['#06b6d4'],
        ];
    }

    #[DataProvider('colours')]
    public function test_hex_survives_a_round_trip_through_oklch(string $hex): void
    {
        [$l, $c, $h] = ColorRamp::hexToOklch($hex);

        $this->assertSame($hex, ColorRamp::oklchToHex($l, $c, $h));
    }

    /**
     * The whole reason the ramp is generated in OKLCH rather than by
     * lightening hex: in sRGB a blue visibly turns purple as it darkens.
     */
    public function test_a_ramp_holds_its_hue_at_every_shade(): void
    {
        $hues = [];

        foreach (ColorRamp::fromHex('#2563eb') as $value) {
            $this->assertMatchesRegularExpression('/^oklch\(/', $value);
            $hues[] = (float) explode(' ', rtrim($value, ')'))[2];
        }

        $this->assertCount(11, $hues);
        $this->assertEqualsWithDelta(max($hues), min($hues), 0.001,
            'The ramp drifted in hue, which is exactly what generating it in OKLCH is supposed to prevent.');
    }

    public function test_a_grey_base_does_not_acquire_a_hue(): void
    {
        foreach (ColorRamp::fromHex('#808080') as $value) {
            $chroma = (float) explode(' ', rtrim($value, ')'))[1];
            $this->assertLessThan(0.02, $chroma);
        }
    }

    public function test_contrast_matches_the_wcag_reference_values(): void
    {
        $this->assertSame(21.0, ColorRamp::contrastRatio('#000000', '#ffffff'));
        $this->assertSame(1.0, ColorRamp::contrastRatio('#ffffff', '#ffffff'));
        $this->assertSame(21.0, ColorRamp::contrastRatio('#ffffff', '#000000'));
    }

    /**
     * Clamping the three channels independently rotates the hue, so a request
     * for orange comes back brown. Reducing chroma keeps the hue asked for.
     */
    public function test_an_out_of_gamut_colour_keeps_its_hue(): void
    {
        $requested = 70.0;

        $this->assertFalse(ColorRamp::inGamut(0.56, 0.17, $requested),
            'This fixture is meant to be outside sRGB; if it is not, the test proves nothing.');

        $hex = ColorRamp::oklchToHex(0.56, 0.17, $requested);

        $this->assertEqualsWithDelta($requested, ColorRamp::hexToOklch($hex)[2], 1.5);
        $this->assertTrue(ColorRamp::inGamut(...ColorRamp::hexToOklch($hex)));
    }

    /**
     * hexToOklch reports an achromatic colour as hue 0, which is red. Without
     * the powerless-hue rule, white mixed a little toward a blue-grey travels
     * through pink and arrives warm.
     */
    public function test_mixing_a_neutral_toward_a_cool_colour_stays_cool(): void
    {
        $mixed = ColorRamp::mix('#ffffff', '#1f2937', 0.14);

        [$r, $g, $b] = ColorRamp::hexToRgb($mixed);

        $this->assertGreaterThanOrEqual($r, $b, "Expected a cool grey, got {$mixed}.");
    }

    public function test_mixing_two_neutrals_stays_neutral(): void
    {
        [$r, $g, $b] = ColorRamp::hexToRgb(ColorRamp::mix('#ffffff', '#808080', 0.5));

        $this->assertSame($r, $g);
        $this->assertSame($g, $b);
    }

    public function test_a_colour_is_walked_until_it_meets_the_contrast_target(): void
    {
        // A vivid accent on a pale page: around 2.2:1 as authored.
        $this->assertLessThan(4.5, ColorRamp::contrastRatio('#06b6d4', '#eef4f8'));

        $fixed = ColorRamp::readableAgainst('#06b6d4', '#eef4f8');

        $this->assertGreaterThanOrEqual(4.5, ColorRamp::contrastRatio($fixed, '#eef4f8'));
        // Same colour, not a different one: the hue must survive the fix.
        $this->assertEqualsWithDelta(
            ColorRamp::hexToOklch('#06b6d4')[2],
            ColorRamp::hexToOklch($fixed)[2],
            3.0,
        );
    }

    public function test_a_colour_that_already_passes_is_left_alone(): void
    {
        $this->assertSame('#0f172a', ColorRamp::readableAgainst('#0f172a', '#ffffff'));
    }

    public function test_readable_ink_is_chosen_by_measurement(): void
    {
        $this->assertSame('#0f172a', ColorRamp::readableOn('#fde047'));
        $this->assertSame('#ffffff', ColorRamp::readableOn('#1e1b4b'));
    }

    public function test_malformed_input_does_not_throw(): void
    {
        $this->assertFalse(ColorRamp::isHex('red'));
        $this->assertFalse(ColorRamp::isHex('#12345'));
        $this->assertTrue(ColorRamp::isHex('#abc'));
        $this->assertTrue(ColorRamp::isHex('2563eb'));
        $this->assertSame([0, 0, 0], ColorRamp::hexToRgb('not a colour'));
    }
}
