<?php

namespace App\Domains\Content\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Banner extends Model
{
    /**
     * Variants map to STATUS TOKENS, never to colours. A banner has to follow
     * the theme like everything else, so the editor offers meanings and the
     * stylesheet decides what they look like.
     */
    public const VARIANTS = ['info' => 'Information', 'success' => 'Success', 'warning' => 'Warning', 'danger' => 'Important'];

    public const AUDIENCES = [
        'everyone' => 'Everyone',
        'guests' => 'Signed-out visitors only',
        'customers' => 'Signed-in customers only',
        'admins' => 'Administrators only',
    ];

    protected $fillable = [
        'uuid', 'title', 'body', 'variant', 'cta_label', 'cta_url', 'priority',
        'starts_at', 'ends_at', 'audience', 'is_dismissible', 'is_active', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_dismissible' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $banner) => $banner->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Scheduled and switched on, right now.
     *
     * Both ends are optional: no start means "already running", no end means
     * "until someone turns it off". An owner announcing maintenance should not
     * have to invent an end date to get the banner to appear.
     */
    public function scopeLive(Builder $query): Builder
    {
        $now = now();

        return $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now));
    }

    public function isLive(): bool
    {
        return $this->is_active
            && ($this->starts_at === null || $this->starts_at->isPast())
            && ($this->ends_at === null || $this->ends_at->isFuture());
    }

    /** Why this banner is not showing, in words an administrator can act on. */
    public function stateLabel(): string
    {
        if (! $this->is_active) {
            return 'Switched off';
        }

        if ($this->starts_at?->isFuture()) {
            return 'Starts '.$this->starts_at->diffForHumans();
        }

        if ($this->ends_at?->isPast()) {
            return 'Ended '.$this->ends_at->diffForHumans();
        }

        return $this->ends_at ? 'Live until '.$this->ends_at->diffForHumans() : 'Live';
    }
}
