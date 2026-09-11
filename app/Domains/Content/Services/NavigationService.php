<?php

namespace App\Domains\Content\Services;

use App\Domains\Content\Models\NavigationItem;
use App\Domains\Content\Models\NavigationMenu;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

/**
 * Navigation is DATA, not code (decision D-11).
 *
 * Phase 1 served this from a declared array. Phase 2 moves the same shape into
 * navigation_menus / navigation_items, so labels, icons, order, visibility and
 * destinations are editable from the Admin Panel. The consuming views did not
 * change, because they already read this shape.
 *
 * The bottom bar is capped at 4 items + More. That cap is not arbitrary: at
 * 320px, six tabs give ~53px each and seven fall below the 44px touch minimum
 * the responsive gate enforces — so it is stored on the menu and the editor
 * refuses a fifth item.
 *
 * FALLBACK. If the tables are missing or empty — a fresh install before
 * seeding, or a database that is down — the built-in navigation is used
 * instead. Navigation is how a customer moves around the product; it cannot be
 * something that disappears.
 */
class NavigationService
{
    public const BOTTOM_BAR_LIMIT = 4;

    private const CACHE_KEY = 'aziv:navigation';

    /** @var array<string, array<int, array<string, mixed>>>|null */
    private ?array $menus = null;

    /** @return array<int, array<string, mixed>> */
    public function primary(): array
    {
        return $this->visible($this->menu('customer_primary'));
    }

    /** Everything behind More / the drawer. */
    public function secondary(): array
    {
        return $this->visible($this->menu('customer_drawer'));
    }

    /** @return array<int, array<string, mixed>> */
    public function bottomBar(): array
    {
        $items = $this->visible($this->menu('customer_bottom_nav'));

        if ($items === []) {
            // A menu that has not been configured falls back to the main one,
            // so a phone is never left without navigation.
            $items = array_filter($this->primary(), fn ($i) => $i['bottom_bar'] ?? true);
        }

        return array_slice(array_values($items), 0, self::BOTTOM_BAR_LIMIT);
    }

    /** @return array<int, array<string, mixed>> */
    public function footer(): array
    {
        return $this->visible($this->menu('footer'));
    }

    public function isActive(array $item): bool
    {
        if (! empty($item['route'])) {
            return request()->routeIs($item['route']) || request()->routeIs($item['route'].'.*');
        }

        return ! empty($item['url']) && request()->is(ltrim($item['url'], '/').'*');
    }

    public function href(array $item): string
    {
        if (! empty($item['route']) && Route::has($item['route'])) {
            return route($item['route']);
        }

        return $item['url'] ?? '#';
    }

    public function flush(): void
    {
        $this->menus = null;
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function menu(string $key): array
    {
        return $this->all()[$key] ?? [];
    }

    /**
     * Every menu, as PLAIN ARRAYS.
     *
     * Deliberately not models: this is cached, and a cached Eloquent model
     * serialises into a payload that outlives its class definition. Rendering
     * navigation costs one cache read.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function all(): array
    {
        if ($this->menus !== null) {
            return $this->menus;
        }

        try {
            $menus = Cache::rememberForever(self::CACHE_KEY, function () {
                $out = [];

                foreach (NavigationMenu::with('items.page')->get() as $menu) {
                    $out[$menu->key] = $menu->items
                        ->map(fn (NavigationItem $item) => $this->toArray($item))
                        // An item whose destination no longer resolves — a
                        // renamed route, a deleted page — is dropped rather
                        // than rendered as a dead link.
                        ->filter(fn (?array $row) => $row !== null)
                        ->values()
                        ->all();
                }

                return $out;
            });
        } catch (\Throwable) {
            $menus = [];
        }

        return $this->menus = ($menus === [] ? $this->builtIn() : $menus);
    }

    /** @return array<string, mixed>|null */
    private function toArray(NavigationItem $item): ?array
    {
        if (! $item->is_visible) {
            return null;
        }

        $href = $item->href();

        if ($href === null) {
            return null;
        }

        return [
            'key' => $item->key,
            'label' => $item->label,
            'icon' => $item->icon,
            'route' => $item->destination_type === 'route' ? $item->route_name : null,
            'url' => $item->destination_type === 'route' ? null : $href,
            'permission' => $item->permission,
            'feature_flag' => $item->feature_flag_key,
            'devices' => $item->device_visibility,
            'bottom_bar' => true,
            'external' => $item->destination_type === 'external' && str_starts_with($href, 'http'),
        ];
    }

    /**
     * The navigation Aziv AI ships with, matching decision D-11's approved set:
     * Chat · Library · Images · Account, with less frequent things behind More.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function builtIn(): array
    {
        return [
            'customer_primary' => [
                ['key' => 'chat', 'label' => __('Chat'), 'icon' => 'chat', 'route' => 'dashboard', 'permission' => null, 'bottom_bar' => true],
                ['key' => 'library', 'label' => __('Library'), 'icon' => 'library', 'route' => 'library', 'permission' => null, 'bottom_bar' => true],
                ['key' => 'images', 'label' => __('Images'), 'icon' => 'image', 'route' => 'images', 'permission' => null, 'bottom_bar' => true],
                ['key' => 'account', 'label' => __('Account'), 'icon' => 'user', 'route' => 'account', 'permission' => null, 'bottom_bar' => true],
            ],
            'customer_drawer' => [
                ['key' => 'admin', 'label' => __('Admin panel'), 'icon' => 'shield', 'url' => '/admin', 'permission' => 'settings.view', 'bottom_bar' => false],
            ],
        ];
    }

    /**
     * A permission on a nav item HIDES the link; it does not grant entry.
     * The destination's own policy still governs — a hidden item is not a
     * security control, and the Admin Panel says so where these are edited.
     */
    private function visible(array $items): array
    {
        $user = Auth::user();
        $flags = app(FeatureFlagService::class);

        return array_values(array_filter($items, function (array $item) use ($user, $flags) {
            if (($item['visible'] ?? true) === false) {
                return false;
            }

            if (! empty($item['feature_flag']) && ! $flags->enabled($item['feature_flag'])) {
                return false;
            }

            if (empty($item['permission'])) {
                return true;
            }

            return $user?->can($item['permission']) ?? false;
        }));
    }
}
