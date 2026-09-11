<?php

namespace App\Domains\Theming\Models;

use App\Domains\Theming\Support\TokenCatalogue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Theme extends Model
{
    protected $fillable = [
        'uuid', 'name', 'slug', 'description', 'is_builtin', 'is_active',
        'supports_dark', 'base_theme_id', 'custom_css', 'version',
        'published_at', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_builtin' => 'boolean',
            'is_active' => 'boolean',
            'supports_dark' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $theme) {
            $theme->uuid ??= (string) Str::uuid();
            $theme->slug ??= Str::slug($theme->name);
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(ThemeToken::class);
    }

    /**
     * @return array<string, string> token_key => value
     */
    public function tokenMap(string $scope = TokenCatalogue::SCOPE_CUSTOMER, string $mode = TokenCatalogue::MODE_LIGHT): array
    {
        return $this->tokens()
            ->where('scope', $scope)
            ->where('mode', $mode)
            ->pluck('token_value', 'token_key')
            ->all();
    }

    /**
     * Built-in themes cannot be deleted — only duplicated. There is therefore
     * always a known-good theme to fall back to, so an owner can never destroy
     * their way into an unusable interface (blueprint §5).
     */
    public function isDeletable(): bool
    {
        return ! $this->is_builtin && ! $this->is_active;
    }
}
