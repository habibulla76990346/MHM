<?php

namespace App\Domains\Content\Services;

use Illuminate\Support\Facades\Auth;

/**
 * Navigation is DATA, not code (decision D-11).
 *
 * Phase 1 serves it from a declared array; Phase 2 moves the same shape into
 * navigation_menus / navigation_items so an administrator can edit labels,
 * icons, order, visibility and destinations without a developer. The
 * consuming views never change, because they already read this shape.
 *
 * The bottom bar is capped at 4 items + More. That cap is not arbitrary: at
 * 320px, six tabs give ~53px each and seven fall below the 44px touch minimum
 * the responsive gate enforces.
 */
class NavigationService
{
    public const BOTTOM_BAR_LIMIT = 4;

    /** @return array<int, array<string, mixed>> */
    public function primary(): array
    {
        return $this->visible([
            [
                'key' => 'chat', 'label' => __('Chat'), 'icon' => 'chat',
                'route' => 'dashboard', 'permission' => null, 'bottom_bar' => true,
            ],
            [
                'key' => 'library', 'label' => __('Library'), 'icon' => 'library',
                'route' => 'library', 'permission' => null, 'bottom_bar' => true,
            ],
            [
                'key' => 'images', 'label' => __('Images'), 'icon' => 'image',
                'route' => 'images', 'permission' => null, 'bottom_bar' => true,
            ],
            [
                'key' => 'account', 'label' => __('Account'), 'icon' => 'user',
                'route' => 'account', 'permission' => null, 'bottom_bar' => true,
            ],
        ]);
    }

    /** Everything behind More / the drawer. */
    public function secondary(): array
    {
        return $this->visible([
            [
                'key' => 'admin', 'label' => __('Admin panel'), 'icon' => 'shield',
                'url' => '/admin', 'permission' => 'settings.view', 'bottom_bar' => false,
            ],
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    public function bottomBar(): array
    {
        return array_slice(
            array_values(array_filter($this->primary(), fn ($i) => $i['bottom_bar'] ?? false)),
            0,
            self::BOTTOM_BAR_LIMIT,
        );
    }

    public function isActive(array $item): bool
    {
        if (isset($item['route'])) {
            return request()->routeIs($item['route']) || request()->routeIs($item['route'].'.*');
        }

        return isset($item['url']) && request()->is(ltrim($item['url'], '/').'*');
    }

    public function href(array $item): string
    {
        if (isset($item['route']) && \Illuminate\Support\Facades\Route::has($item['route'])) {
            return route($item['route']);
        }

        return $item['url'] ?? '#';
    }

    /**
     * A permission on a nav item HIDES the link; it does not grant entry.
     * The destination's own policy still governs — a hidden item is not a
     * security control, and the Admin Panel says so where these are edited.
     */
    private function visible(array $items): array
    {
        $user = Auth::user();

        return array_values(array_filter($items, function (array $item) use ($user) {
            if (($item['visible'] ?? true) === false) {
                return false;
            }

            if (empty($item['permission'])) {
                return true;
            }

            return $user?->can($item['permission']) ?? false;
        }));
    }
}
