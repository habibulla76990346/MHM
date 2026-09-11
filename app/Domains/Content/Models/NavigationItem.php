<?php

namespace App\Domains\Content\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Route;

class NavigationItem extends Model
{
    public const DESTINATIONS = [
        'route' => 'A built-in screen',
        'page' => 'A page you have written',
        'external' => 'Another website',
    ];

    public const DEVICES = ['mobile' => 'Phone', 'tablet' => 'Tablet', 'desktop' => 'Desktop'];

    protected $fillable = [
        'menu_id', 'parent_id', 'key', 'label', 'icon', 'destination_type',
        'route_name', 'page_id', 'url', 'sort_order', 'is_visible',
        'device_visibility', 'permission', 'feature_flag_key',
    ];

    protected function casts(): array
    {
        return [
            'is_visible' => 'boolean',
            'device_visibility' => 'array',
        ];
    }

    public function menu(): BelongsTo
    {
        return $this->belongsTo(NavigationMenu::class, 'menu_id');
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'page_id');
    }

    /**
     * Where this item points.
     *
     * Returns null rather than '#' when the destination cannot be resolved —
     * a renamed route, a deleted page — so the caller can drop the item
     * instead of rendering a link that goes nowhere.
     */
    public function href(): ?string
    {
        return match ($this->destination_type) {
            'route' => $this->route_name && Route::has($this->route_name) ? route($this->route_name) : null,
            'page' => $this->page && $this->page->isLive() ? url('/p/'.$this->page->slug) : null,
            'external' => $this->safeExternalUrl(),
            default => null,
        };
    }

    public function showsOn(string $device): bool
    {
        $devices = $this->device_visibility;

        // Not configured means every device. An administrator adding an item
        // should get a working link, not an invisible one.
        return ! is_array($devices) || $devices === [] || in_array($device, $devices, true);
    }

    /**
     * Only http(s), and never a javascript: or data: URL.
     *
     * An administrator types this, and an administrator is trusted — but a
     * navigation label is rendered for every visitor, so a mistake here would
     * be everyone's problem, not just theirs.
     */
    private function safeExternalUrl(): ?string
    {
        $url = trim((string) $this->url);

        if ($url === '') {
            return null;
        }

        // A site-relative path is fine and common ("/pricing").
        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return $url;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $url : null;
    }
}
