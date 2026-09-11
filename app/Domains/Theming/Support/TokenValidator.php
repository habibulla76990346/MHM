<?php

namespace App\Domains\Theming\Support;

/**
 * Validates a token value at the point an administrator saves it.
 *
 * ThemeService::safeValue() already strips the characters that could close a
 * CSS declaration and start something else, so this is not the only control —
 * but a value stripped silently at render time looks to the administrator like
 * the theme editor ignored them. Rejecting it here, with a reason, is the
 * difference between a confusing editor and a usable one.
 *
 * Each type is an ALLOWLIST. A value that does not match a known-safe shape is
 * refused rather than sanitised into something the administrator did not ask
 * for.
 */
class TokenValidator
{
    /** CSS lengths a theme legitimately needs: 0, 12px, 0.75rem, 2em, 50%. */
    private const LENGTH = '/^(0|(\d+(\.\d+)?)(px|rem|em|%|vw|vh|ch))$/';

    /**
     * Characters a shadow may contain, and the colour functions it may call.
     *
     * Parsing CSS with a regex was the first attempt and it was wrong: it
     * rejected `0 1px 2px rgb(0 0 0 / 0.06)` — a shadow this application
     * generates itself — because the leading `0` carries no unit. Enumerating
     * every valid shadow grammar is not the job here. Excluding everything
     * dangerous is, and that is a much smaller list.
     */
    private const SHADOW_CHARS = '/^[0-9a-zA-Z#.,%\/ ()\-]+$/';

    /** The only functions a shadow may call. Notably absent: url() and image(). */
    private const SHADOW_FUNCTIONS = ['rgb', 'rgba', 'hsl', 'hsla', 'oklch', 'oklab'];

    /** A font stack: names, quotes, commas, hyphens. No functions, no URLs. */
    private const FONT = '/^[A-Za-z0-9 ,\'"\-_]+$/';

    /**
     * @param  array<string, string>  $values  token_key => value
     * @return array<string, string> token_key => error message, empty when all valid
     */
    public static function validate(array $values): array
    {
        $catalogue = TokenCatalogue::tokens();
        $errors = [];

        foreach ($values as $key => $value) {
            $definition = $catalogue[$key] ?? null;

            if (! $definition) {
                // A key that is not in the catalogue cannot be edited: the
                // catalogue is the contract for what is themeable.
                $errors[$key] = 'Not a themeable value.';

                continue;
            }

            $error = self::check($definition, trim((string) $value));

            if ($error !== null) {
                $errors[$key] = $error;
            }
        }

        return $errors;
    }

    private static function checkShadow(string $value): ?string
    {
        if ($value === 'none') {
            return null;
        }

        if (! preg_match(self::SHADOW_CHARS, $value)) {
            return 'Must be a shadow such as 0 4px 12px rgb(0 0 0 / 0.1), or none.';
        }

        // Every function call in the value must be one of the colour
        // functions. This is what keeps url() — and anything else that fetches
        // from a third party — out of a token that reaches the stylesheet.
        preg_match_all('/([a-zA-Z-]+)\s*\(/', $value, $matches);

        foreach ($matches[1] as $function) {
            if (! in_array(strtolower($function), self::SHADOW_FUNCTIONS, true)) {
                return "Shadows may not use {$function}(). Use a colour such as rgb(0 0 0 / 0.1).";
            }
        }

        return null;
    }

    /** @param array{type: string, label: string, choices?: array<string,string>} $definition */
    private static function check(array $definition, string $value): ?string
    {
        if ($value === '') {
            return 'Cannot be empty.';
        }

        if (mb_strlen($value) > 200) {
            return 'Too long — 200 characters at most.';
        }

        return match ($definition['type']) {
            'color' => ColorRamp::isHex($value)
                ? null
                : 'Must be a colour such as #2563eb.',

            'length' => preg_match(self::LENGTH, $value)
                ? null
                : 'Must be a size such as 12px, 0.75rem or 0.',

            'number' => is_numeric($value)
                ? null
                : 'Must be a number.',

            'shadow' => self::checkShadow($value),

            'font' => preg_match(self::FONT, $value)
                ? null
                : 'Must be font names only, separated by commas.',

            'choice' => isset($definition['choices'][$value])
                ? null
                : 'Choose one of the listed options.',

            default => 'Unknown value type.',
        };
    }
}
