<?php

namespace App\Domains\Content\Services;

use App\Domains\Content\Models\Banner;
use Illuminate\Support\Facades\Auth;

/**
 * Which announcement, if any, a given visitor should see right now
 * (blueprint §7).
 *
 * One at a time, deliberately. Two stacked banners push the page content below
 * the fold on a phone, and Owner Addendum A is explicit that mobile must not
 * be a squeezed desktop. Priority decides which one wins.
 */
class BannerService
{
    private const DISMISSED_SESSION_KEY = 'aziv.dismissed_banners';

    public function current(): ?Banner
    {
        $dismissed = (array) session(self::DISMISSED_SESSION_KEY, []);

        return Banner::live()
            ->whereIn('audience', $this->audiencesFor())
            ->whereNotIn('uuid', $dismissed ?: ['-'])
            ->orderByDesc('priority')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Dismissal is per SESSION, not per account.
     *
     * A signed-out visitor has no account to record it against, and writing a
     * row for every dismissal by every anonymous visitor would grow without
     * bound for a banner that is switched off next week.
     */
    public function dismiss(string $uuid): void
    {
        $dismissed = (array) session(self::DISMISSED_SESSION_KEY, []);
        $dismissed[] = $uuid;

        session([self::DISMISSED_SESSION_KEY => array_values(array_unique($dismissed))]);
    }

    /** @return array<int, string> */
    private function audiencesFor(): array
    {
        $user = Auth::user();

        if (! $user) {
            return ['everyone', 'guests'];
        }

        // "Administrators only" means anyone who can open the Admin Panel —
        // the audience is about who sees the message, not about permission.
        return $user->can('settings.view')
            ? ['everyone', 'customers', 'admins']
            : ['everyone', 'customers'];
    }
}
