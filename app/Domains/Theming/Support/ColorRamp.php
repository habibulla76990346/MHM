<?php

namespace App\Domains\Theming\Support;

/**
 * Generates a 50–950 shade ramp from a single brand colour.
 *
 * WHY THIS EXISTS (owner decision D-07)
 * -------------------------------------
 * Filament styles itself from a full shade ramp per colour, not a single hex.
 * So when an administrator picks one primary colour, Aziv AI has to produce
 * the other eleven steps.
 *
 * The ramp is built in OKLCH rather than by lightening and darkening the hex.
 * Naive sRGB interpolation is not perceptually even: the light end washes out
 * and the dark end goes muddy, and hue visibly drifts — blues turn purple as
 * they darken. OKLCH is perceptually uniform, so equal steps in lightness look
 * like equal steps to the eye, and hue stays put.
 *
 * Chroma is tapered at both extremes because a very light or very dark colour
 * cannot hold full saturation without leaving the sRGB gamut, which clips to
 * something dirty.
 */
class ColorRamp
{
    /** Target lightness per shade, in OKLCH terms. */
    private const LIGHTNESS = [
        50 => 0.971, 100 => 0.936, 200 => 0.885, 300 => 0.808,
        400 => 0.704, 500 => 0.637, 600 => 0.577, 700 => 0.505,
        800 => 0.443, 900 => 0.396, 950 => 0.28,
    ];

    /** How much of the base chroma each shade keeps. */
    private const CHROMA_SCALE = [
        50 => 0.18, 100 => 0.32, 200 => 0.55, 300 => 0.78,
        400 => 0.95, 500 => 1.0, 600 => 0.98, 700 => 0.88,
        800 => 0.76, 900 => 0.66, 950 => 0.48,
    ];

    /**
     * @return array<int, string> shade => 'oklch(L C H)'
     */
    public static function fromHex(string $hex): array
    {
        [$l, $c, $h] = self::hexToOklch($hex);

        // A grey or near-grey base has no meaningful hue; keep it neutral
        // rather than inventing one.
        $isNeutral = $c < 0.02;

        $ramp = [];

        foreach (self::LIGHTNESS as $shade => $lightness) {
            $chroma = $isNeutral ? $c : round($c * self::CHROMA_SCALE[$shade], 4);
            $ramp[$shade] = sprintf('oklch(%s %s %s)', round($lightness, 4), $chroma, round($h, 3));
        }

        return $ramp;
    }

    /** @return array{0: float, 1: float, 2: float} [L, C, H] */
    public static function hexToOklch(string $hex): array
    {
        [$r, $g, $b] = self::hexToRgb($hex);

        // sRGB -> linear
        $lr = self::toLinear($r / 255);
        $lg = self::toLinear($g / 255);
        $lb = self::toLinear($b / 255);

        // linear sRGB -> LMS (OKLab matrix)
        $l = 0.4122214708 * $lr + 0.5363325363 * $lg + 0.0514459929 * $lb;
        $m = 0.2119034982 * $lr + 0.6806995451 * $lg + 0.1073969566 * $lb;
        $s = 0.0883024619 * $lr + 0.2817188376 * $lg + 0.6299787005 * $lb;

        $l_ = self::cbrt($l);
        $m_ = self::cbrt($m);
        $s_ = self::cbrt($s);

        // LMS -> OKLab
        $L = 0.2104542553 * $l_ + 0.7936177850 * $m_ - 0.0040720468 * $s_;
        $a = 1.9779984951 * $l_ - 2.4285922050 * $m_ + 0.4505937099 * $s_;
        $bb = 0.0259040371 * $l_ + 0.7827717662 * $m_ - 0.8086757660 * $s_;

        // OKLab -> OKLCH
        $C = sqrt($a * $a + $bb * $bb);
        $H = $C < 1e-6 ? 0.0 : fmod(rad2deg(atan2($bb, $a)) + 360, 360);

        return [$L, $C, $H];
    }

    /**
     * Relative luminance, for the WCAG contrast check.
     * Deliberately the WCAG definition rather than OKLCH lightness — the
     * accessibility threshold is defined against this specific formula.
     */
    public static function relativeLuminance(string $hex): float
    {
        [$r, $g, $b] = self::hexToRgb($hex);

        return 0.2126 * self::toLinear($r / 255)
             + 0.7152 * self::toLinear($g / 255)
             + 0.0722 * self::toLinear($b / 255);
    }

    /** WCAG 2.x contrast ratio, 1.0 to 21.0. */
    public static function contrastRatio(string $foreground, string $background): float
    {
        $a = self::relativeLuminance($foreground);
        $b = self::relativeLuminance($background);

        [$lighter, $darker] = $a > $b ? [$a, $b] : [$b, $a];

        return round(($lighter + 0.05) / ($darker + 0.05), 2);
    }

    /** @return array{0: int, 1: int, 2: int} */
    public static function hexToRgb(string $hex): array
    {
        $hex = ltrim(trim($hex), '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        if (strlen($hex) === 8) {
            $hex = substr($hex, 0, 6);   // drop alpha
        }

        if (! preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            return [0, 0, 0];
        }

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    public static function isHex(string $value): bool
    {
        return (bool) preg_match('/^#?([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', trim($value));
    }

    /**
     * OKLCH -> hex. The inverse of hexToOklch, used to DERIVE tokens from a
     * palette: "surface, a little lighter" is a perceptual instruction, and
     * doing it in sRGB gives uneven, hue-drifting results.
     */
    public static function oklchToHex(float $L, float $C, float $H): string
    {
        // Reduce chroma until the colour actually fits in sRGB. Clamping the
        // three channels instead would be simpler and wrong: clamping one
        // channel and not the others rotates the hue, so a request for an
        // orange warning at full chroma comes back brown. Dropping chroma
        // keeps the hue exactly where it was asked for.
        $C = self::fitChroma($L, $C, $H);

        return self::project($L, $C, $H);
    }

    /**
     * Nudge a colour's lightness until it meets a contrast ratio against a
     * background, without touching its hue.
     *
     * A brand accent is chosen to look good, not to be legible at body-text
     * size, so link colours routinely land around 2:1 on a pale page. Rather
     * than silently shipping that, the derivation walks the colour toward the
     * side with more room until it passes.
     */
    public static function readableAgainst(string $hex, string $background, float $target = 4.5): string
    {
        if (self::contrastRatio($hex, $background) >= $target) {
            return $hex;
        }

        [$L, $C, $H] = self::hexToOklch($hex);

        // Move away from the background: darken on a light page, lighten on a
        // dark one. Going the other way can never reach the target.
        $step = self::relativeLuminance($background) > 0.18 ? -0.02 : 0.02;
        $best = $hex;

        for ($i = 1; $i <= 50; $i++) {
            $candidate = self::oklchToHex(max(0.0, min(1.0, $L + $step * $i)), $C, $H);
            $best = $candidate;

            if (self::contrastRatio($candidate, $background) >= $target) {
                return $candidate;
            }
        }

        return $best;
    }

    /** Is this OKLCH colour representable in sRGB? */
    public static function inGamut(float $L, float $C, float $H): bool
    {
        foreach (self::toLinearRgb($L, $C, $H) as $channel) {
            if ($channel < -0.0001 || $channel > 1.0001) {
                return false;
            }
        }

        return true;
    }

    private static function fitChroma(float $L, float $C, float $H): float
    {
        if ($C <= 0 || self::inGamut($L, $C, $H)) {
            return $C;
        }

        $low = 0.0;
        $high = $C;

        for ($i = 0; $i < 18; $i++) {
            $mid = ($low + $high) / 2;

            if (self::inGamut($L, $mid, $H)) {
                $low = $mid;
            } else {
                $high = $mid;
            }
        }

        return $low;
    }

    /** @return array{0: float, 1: float, 2: float} linear sRGB */
    private static function toLinearRgb(float $L, float $C, float $H): array
    {
        $hRad = deg2rad($H);
        $a = $C * cos($hRad);
        $b = $C * sin($hRad);

        $l_ = $L + 0.3963377774 * $a + 0.2158037573 * $b;
        $m_ = $L - 0.1055613458 * $a - 0.0638541728 * $b;
        $s_ = $L - 0.0894841775 * $a - 1.2914855480 * $b;

        $l = $l_ ** 3;
        $m = $m_ ** 3;
        $s = $s_ ** 3;

        $lr = 4.0767416621 * $l - 3.3077115913 * $m + 0.2309699292 * $s;
        $lg = -1.2684380046 * $l + 2.6097574011 * $m - 0.3413193965 * $s;
        $lb = -0.0041960863 * $l - 0.7034186147 * $m + 1.7076147010 * $s;

        return [$lr, $lg, $lb];
    }

    private static function project(float $L, float $C, float $H): string
    {
        [$lr, $lg, $lb] = self::toLinearRgb($L, $C, $H);

        return '#'.self::channelToHex($lr).self::channelToHex($lg).self::channelToHex($lb);
    }

    /**
     * Move a colour's perceptual lightness without shifting its hue.
     * $delta is in OKLCH lightness units: +0.05 is "slightly lighter".
     */
    public static function adjustLightness(string $hex, float $delta, float $chromaScale = 1.0): string
    {
        [$L, $C, $H] = self::hexToOklch($hex);

        return self::oklchToHex(
            max(0.0, min(1.0, $L + $delta)),
            max(0.0, $C * $chromaScale),
            $H,
        );
    }

    /** Mix two colours perceptually. $weight 0 = first, 1 = second. */
    public static function mix(string $a, string $b, float $weight = 0.5): string
    {
        [$la, $ca, $ha] = self::hexToOklch($a);
        [$lb, $cb, $hb] = self::hexToOklch($b);

        // A grey has no hue to interpolate FROM, and hexToOklch reports it as
        // 0 degrees — which is red. Mixing white a little way toward a blue-grey
        // would then travel through pink and come back a warm grey. CSS Color 4
        // calls such a hue "powerless": adopt the other end's instead, so a
        // neutral mixed toward a cool colour stays cool.
        if ($ca < 1e-4) {
            $ha = $hb;
        } elseif ($cb < 1e-4) {
            $hb = $ha;
        }

        // Interpolate hue the short way round the circle.
        $dh = $hb - $ha;
        if ($dh > 180) {
            $dh -= 360;
        } elseif ($dh < -180) {
            $dh += 360;
        }

        return self::oklchToHex(
            $la + ($lb - $la) * $weight,
            $ca + ($cb - $ca) * $weight,
            fmod($ha + $dh * $weight + 360, 360),
        );
    }

    /**
     * Black or white, whichever is readable on the given background.
     * Used for text-on-primary so an administrator cannot accidentally
     * produce white-on-yellow.
     */
    public static function readableOn(string $background, string $light = '#ffffff', string $dark = '#0f172a'): string
    {
        return self::contrastRatio($light, $background) >= self::contrastRatio($dark, $background)
            ? $light
            : $dark;
    }

    private static function channelToHex(float $linear): string
    {
        $srgb = $linear <= 0.0031308
            ? 12.92 * $linear
            : 1.055 * ($linear ** (1 / 2.4)) - 0.055;

        $byte = (int) round(max(0.0, min(1.0, $srgb)) * 255);

        return str_pad(dechex($byte), 2, '0', STR_PAD_LEFT);
    }

    private static function toLinear(float $channel): float
    {
        return $channel <= 0.04045
            ? $channel / 12.92
            : (($channel + 0.055) / 1.055) ** 2.4;
    }

    private static function cbrt(float $x): float
    {
        return $x < 0 ? -((-$x) ** (1 / 3)) : $x ** (1 / 3);
    }
}
