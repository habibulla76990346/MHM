<?php

namespace App\Domains\Content\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Page extends Model
{
    use SoftDeletes;

    protected $table = 'content_pages';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    protected $fillable = [
        'uuid', 'slug', 'title', 'status', 'is_system',
        'show_in_footer', 'sort_order', 'seo', 'published_at', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'show_in_footer' => 'boolean',
            'seo' => 'array',
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $page) {
            $page->uuid ??= (string) Str::uuid();
            $page->slug ??= Str::slug($page->title);
        });
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function sections(): HasMany
    {
        return $this->hasMany(PageSection::class, 'page_id')->orderBy('sort_order');
    }

    /**
     * Live right now.
     *
     * Scheduling is a published_at in the future rather than a separate flag,
     * so there is exactly one answer to "is this visible?" — a page cannot be
     * marked published and scheduled for next week at the same time.
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED)
            ->where(function (Builder $q) {
                $q->whereNull('published_at')->orWhere('published_at', '<=', now());
            });
    }

    public function isLive(): bool
    {
        return $this->status === self::STATUS_PUBLISHED
            && ($this->published_at === null || $this->published_at->isPast());
    }

    public function isScheduled(): bool
    {
        return $this->status === self::STATUS_PUBLISHED
            && $this->published_at !== null
            && $this->published_at->isFuture();
    }

    /** System pages are referenced by the footer and legal links; never deletable. */
    public function isDeletable(): bool
    {
        return ! $this->is_system;
    }

    public function seoValue(string $key, ?string $fallback = null): ?string
    {
        $value = $this->seo[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? $value : $fallback;
    }
}
