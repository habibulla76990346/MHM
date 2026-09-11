<?php

namespace App\Domains\Theming\Services;

/**
 * Custom CSS is the one genuinely dangerous feature in the theme system
 * (blueprint §5: "optional custom CSS field with strict permission and
 * sanitization controls").
 *
 * Arbitrary CSS can hide interface elements, overlay fake content, or fetch
 * remote resources that leak a visitor's IP and referrer to a third party.
 * Permission gating (Super Admin only, via themes.custom_css) is the first
 * control; this is the second.
 */
class CssSanitiser
{
    /** Constructs removed outright. */
    private const FORBIDDEN = [
        // Pulls in a remote stylesheet — arbitrary third-party CSS.
        '/@import\b[^;]*;?/i',
        // Legacy IE script execution.
        '/\bexpression\s*\(/i',
        '/\bbehaviou?r\s*:/i',
        '/-moz-binding\s*:/i',
        // javascript:/vbscript: URLs.
        '/\b(?:java|vb)script\s*:/i',
        // CSS-in-HTML escape attempts.
        '/<\s*\/?\s*(?:style|script|iframe|object|embed)/i',
        // @charset can shift parsing.
        '/@charset\b[^;]*;?/i',
    ];

    public function sanitise(?string $css): string
    {
        if ($css === null || trim($css) === '') {
            return '';
        }

        // Cap length before any work: a megabyte of CSS is an attack, not a theme.
        $css = mb_substr($css, 0, 50000);

        // Strip comments first, so a construct cannot hide inside one and be
        // re-assembled by a lenient parser.
        $css = preg_replace('#/\*.*?\*/#s', '', $css) ?? '';

        // url() is handled separately and DEFAULT-DENY. Stripping just the
        // scheme, as an earlier version did, turned
        //     url(https://evil.test/a.png)
        // into
        //     evil.test/a.png)
        // which relies on the browser rejecting the wreckage. Relying on a
        // parser to reject something is not a control; removing the whole
        // expression is.
        $css = preg_replace_callback(
            '/url\s*\(\s*([\'"]?)([^)\'"]*)\1\s*\)/i',
            function (array $m): string {
                $target = trim($m[2]);

                // A relative path is the only thing a theme legitimately needs:
                // every asset a theme references is one Aziv AI itself serves.
                // Anything with a scheme, and anything protocol-relative, goes.
                $isRelative = $target !== ''
                    && ! str_contains($target, ':')
                    && ! str_starts_with($target, '//');

                return $isRelative ? "url({$target})" : 'none';
            },
            $css,
        ) ?? $css;

        foreach (self::FORBIDDEN as $pattern) {
            $css = preg_replace($pattern, '', $css) ?? $css;
        }

        // Never let custom CSS break out of its scope wrapper.
        $css = str_replace(['</style', '<style'], '', $css);

        return trim($css);
    }

    /**
     * Wrapped so it cannot restyle the admin panel's own chrome or anything
     * security-critical from the customer side.
     */
    public function scope(string $css, string $selector = '.aziv-themed'): string
    {
        $css = $this->sanitise($css);

        return $css === '' ? '' : $selector.' { }'."\n".$css;
    }

    /** @return array<int, string> what was removed, for the admin to see */
    public function report(?string $css): array
    {
        if ($css === null || trim($css) === '') {
            return [];
        }

        $found = [];
        $labels = [
            '/@import\b/i' => '@import (remote stylesheets)',
            '/\bexpression\s*\(/i' => 'expression() (script execution)',
            '/\bbehaviou?r\s*:/i' => 'behavior (script execution)',
            '/\b(?:java|vb)script\s*:/i' => 'javascript: URLs',
            '/url\s*\(\s*[\'"]?\s*(?:https?:)?\/\//i' => 'remote url() fetches',
            '/url\s*\(\s*[\'"]?\s*data:/i' => 'data: URLs',
        ];

        foreach ($labels as $pattern => $label) {
            if (preg_match($pattern, $css)) {
                $found[] = $label;
            }
        }

        return $found;
    }
}
