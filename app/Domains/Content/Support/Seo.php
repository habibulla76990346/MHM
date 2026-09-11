<?php

namespace App\Domains\Content\Support;

use App\Domains\Content\Models\Page;

/**
 * The tags a page needs to look right in a search result and in a link
 * preview (blueprint §7).
 *
 * Every value falls back: page override, then the page's own content, then
 * branding. A page an owner never filled in still previews correctly, which
 * is the only version of this feature that survives contact with real use.
 */
class Seo
{
    public const TITLE_MAX = 60;

    public const DESCRIPTION_MAX = 160;

    /**
     * @return array{
     *     title: string, description: string, canonical: string,
     *     image: string, robots: string, type: string, site_name: string
     * }
     */
    public static function forPage(?Page $page = null, ?string $fallbackTitle = null): array
    {
        $siteName = (string) settings('branding.app_name');

        $title = $page?->seoValue('title')
            ?? $page?->title
            ?? $fallbackTitle
            ?? $siteName;

        // The product name belongs in the tab, but not twice.
        $fullTitle = $title === $siteName ? $siteName : $title.' · '.$siteName;

        $description = $page?->seoValue('description')
            ?? self::fromFirstSection($page)
            ?? (string) settings('branding.tagline');

        return [
            'title' => self::clamp($fullTitle, 90),
            'description' => self::clamp($description, self::DESCRIPTION_MAX),
            'canonical' => $page ? url('/p/'.$page->slug) : url('/'),
            // The social preview image: an owner's own, or the app icon, which
            // is always present because branding falls back to shipped artwork.
            'image' => asset($page?->seoValue('image') ?? brand('app_icon')),
            // A page that is not live must never be indexed, even if someone
            // is given the preview link.
            'robots' => ($page === null || $page->isLive()) ? 'index, follow' : 'noindex, nofollow',
            'type' => 'website',
            'site_name' => $siteName,
        ];
    }

    /** The first readable words of the page, when nobody wrote a description. */
    private static function fromFirstSection(?Page $page): ?string
    {
        if (! $page) {
            return null;
        }

        foreach ($page->sections as $section) {
            if (! $section->is_visible) {
                continue;
            }

            foreach (['subheading', 'body'] as $field) {
                $text = trim($section->value($field));

                if ($text !== '') {
                    return $text;
                }
            }
        }

        return null;
    }

    private static function clamp(string $value, int $limit): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');

        return mb_strlen($value) <= $limit ? $value : rtrim(mb_substr($value, 0, $limit - 1)).'…';
    }
}
