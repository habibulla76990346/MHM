<?php

namespace App\Domains\Content\Services;

use App\Domains\Content\Models\Faq;
use App\Domains\Content\Models\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Reads published content for the customer site (blueprint §7).
 *
 * Everything here is cached by SLUG AND VERSION-FREE KEY and flushed on write,
 * and everything cached is a plain id or array — never an Eloquent model. A
 * cached model serialises, and a serialised model outlives the class that
 * wrote it; that has already taken this application down twice.
 */
class ContentService
{
    private const CACHE_PREFIX = 'aziv:content:';

    /** The page at this slug, if it is live right now. */
    public function page(string $slug): ?Page
    {
        $id = Cache::remember(
            self::CACHE_PREFIX.'page:'.$slug,
            now()->addMinutes(10),
            fn () => Page::live()->where('slug', $slug)->value('id') ?? 0,
        );

        if (! $id) {
            return null;
        }

        $page = Page::with('sections')->find($id);

        // The cache can outlive a schedule expiring, so the freshly loaded
        // page is re-checked rather than trusted.
        return $page?->isLive() ? $page : null;
    }

    /** Footer links, in the order an administrator set. */
    public function footerPages(): Collection
    {
        return Page::live()
            ->where('show_in_footer', true)
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get(['id', 'slug', 'title']);
    }

    /** @return Collection<int, Faq> */
    public function faqs(?string $category = null): Collection
    {
        return Faq::published()
            ->when($category, fn ($q) => $q->where('category', $category))
            ->get();
    }

    /** @return array<int, string> the categories that actually have published questions */
    public function faqCategories(): array
    {
        return Faq::published()
            ->distinct()
            ->orderBy('category')
            ->pluck('category')
            ->all();
    }

    public function flush(): void
    {
        foreach (Page::withTrashed()->pluck('slug') as $slug) {
            Cache::forget(self::CACHE_PREFIX.'page:'.$slug);
        }
    }
}
