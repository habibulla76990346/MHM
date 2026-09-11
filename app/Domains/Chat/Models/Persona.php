<?php

namespace App\Domains\Chat\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * An administrator's system prompt (§15).
 *
 * Written in the Admin Panel, never by a customer. A customer-supplied system
 * prompt is how a product's guardrails get talked away, and the whole point of
 * a persona is that the owner decides how their product behaves.
 */
class Persona extends Model
{
    protected $fillable = [
        'uuid', 'name', 'description', 'system_prompt', 'is_default',
        'plan_restrictions', 'status', 'sort_order', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'plan_restrictions' => 'array'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $p) => $p->uuid ??= (string) Str::uuid());

        // Exactly one default, enforced here rather than hoped for: two
        // defaults would make which prompt a new chat gets depend on row order.
        static::saved(function (self $persona) {
            if ($persona->is_default) {
                static::where('id', '!=', $persona->getKey())
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active')->orderBy('sort_order')->orderBy('name');
    }

    public static function default(): ?self
    {
        return static::active()->where('is_default', true)->first();
    }
}
