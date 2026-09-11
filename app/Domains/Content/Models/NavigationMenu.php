<?php

namespace App\Domains\Content\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NavigationMenu extends Model
{
    /**
     * The bottom bar's cap is a CONSEQUENCE of Owner Addendum A, not a taste:
     * at 320px, six tabs give about 53px each and seven fall under the 44px
     * touch minimum the responsive gate enforces. Storing it on the menu means
     * the editor can refuse a fifth item and say why.
     */
    public const LOCATIONS = [
        'customer_primary' => 'Customer — main navigation',
        'customer_bottom_nav' => 'Customer — phone bottom bar',
        'customer_drawer' => 'Customer — More / drawer',
        'footer' => 'Footer',
    ];

    protected $fillable = ['key', 'name', 'location', 'max_items', 'is_system'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    public function getRouteKeyName(): string
    {
        return 'key';
    }

    public function items(): HasMany
    {
        return $this->hasMany(NavigationItem::class, 'menu_id')->orderBy('sort_order');
    }

    public function isFull(): bool
    {
        return $this->max_items !== null && $this->items()->count() >= $this->max_items;
    }
}
