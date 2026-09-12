<?php

namespace App\Domains\Notifications\Support;

use App\Domains\Diagnostics\Support\Redactor;

/**
 * Turning a template plus a set of values into the words a customer reads.
 *
 * THREE RULES, and each one closes a way this could leak or break:
 *
 *  1. **Only DECLARED variables are substituted.** The event says which values
 *     exist; anything else in the template is not a value we happen not to
 *     have, it is a value the template was never given. Substituting from an
 *     open bag would mean a template could be edited to print whatever
 *     happened to be passed in.
 *
 *  2. **Anything left over is REMOVED.** A template that survives an event
 *     losing a variable must not mail `{{pay_url}}` to a customer.
 *
 *  3. **Every value is SCRUBBED before it is placed.** The values come from
 *     the billing layer and should never contain a secret — this is the last
 *     line, for the same reason the diagnostics layer has one: an exception
 *     message or a URL can carry a token, and an email is forwarded, printed
 *     and stored on servers nobody here controls.
 *
 * Substitution is literal. Nothing here is evaluated, so a template is
 * configuration and never code — the same rule the custom provider mapping
 * builder follows.
 */
final class TemplateRenderer
{
    /** `{{ name }}`, with or without spaces. */
    private const PLACEHOLDER = '/\{\{\s*([a-z0-9_]+)\s*\}\}/i';

    /**
     * @param  array<string, string>  $declared  variable name => meaning
     * @param  array<string, mixed>  $values
     */
    public static function render(string $template, array $declared, array $values): string
    {
        $rendered = preg_replace_callback(
            self::PLACEHOLDER,
            function (array $match) use ($declared, $values): string {
                $name = strtolower($match[1]);

                // Rules 1 and 2: undeclared, or declared but absent, both
                // vanish rather than reaching a customer as punctuation.
                if (! array_key_exists($name, $declared) || ! array_key_exists($name, $values)) {
                    return '';
                }

                return self::clean($values[$name]);
            },
            $template,
        ) ?? '';

        // A removed placeholder can leave a double space or a stranded blank
        // line. Tidy both, so a missing value never looks like a broken email.
        $rendered = preg_replace('/[ \t]{2,}/', ' ', $rendered) ?? $rendered;

        return trim((string) preg_replace('/\n{3,}/', "\n\n", $rendered));
    }

    /**
     * Which placeholders a template uses that the event does not provide.
     *
     * Used by the editing screen to refuse a template before it is saved,
     * rather than letting an administrator find out from a customer that
     * half the email was blank.
     *
     * @param  array<string, string>  $declared
     * @return array<int, string>
     */
    public static function unknownPlaceholders(string $template, array $declared): array
    {
        preg_match_all(self::PLACEHOLDER, $template, $matches);

        $used = array_map('strtolower', $matches[1] ?? []);

        return array_values(array_unique(array_diff($used, array_keys($declared))));
    }

    /** Rule 3, plus flattening whatever the caller passed into text. */
    private static function clean(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if ($value === null || is_array($value) || is_object($value)) {
            return '';
        }

        $text = (string) $value;

        return self::isOwnLink($text) ? $text : Redactor::scrub($text);
    }

    /**
     * A link back to this platform, which must survive the scrubber intact.
     *
     * THIS EXCEPTION EXISTS FOR ONE REASON AND IS AS NARROW AS IT CAN BE. A
     * signed payment link ends in a long opaque signature, and the scrubber
     * removes long opaque strings — correctly, since that is also what a
     * bearer token looks like. Scrubbing it would mail the customer a broken
     * link and nobody would notice until they tried to pay.
     *
     * So the carve-out is: absolute, http(s), and the SAME HOST this
     * application is configured to serve. A link anywhere else is scrubbed
     * like any other value, which is what stops this becoming a hole through
     * which an arbitrary token could be mailed out.
     */
    private static function isOwnLink(string $value): bool
    {
        $parts = parse_url(trim($value));

        if (! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)) {
            return false;
        }

        $ownHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        return is_string($ownHost)
            && $ownHost !== ''
            && strcasecmp((string) ($parts['host'] ?? ''), $ownHost) === 0;
    }
}
