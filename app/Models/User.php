<?php

namespace App\Models;

use App\Domains\Identity\Models\UserProfile;
use App\Domains\Identity\Models\UserSession;
use App\Domains\Security\Services\PermissionRegistry;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser, MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    // Aliased so the override below can delegate: hasPermissionTo comes from a
    // trait, not a parent class, so parent:: does not reach it.
    use HasRoles {
        hasPermissionTo as protected spatieHasPermissionTo;
    }

    public const STATUS_ACTIVE = 'active';
    public const STATUS_PENDING = 'pending';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_RESTRICTED = 'restricted';

    protected $fillable = [
        'uuid', 'name', 'email', 'password', 'status', 'locale', 'timezone',
    ];

    protected $hidden = [
        'password', 'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'suspended_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $user) {
            // Public identifiers are UUIDs so record counts are never exposed
            // in URLs, and so file/resource ids are not guessable.
            $user->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * Who may reach the Admin Panel.
     *
     * Deny by default: only the named admin roles get in, and a suspended
     * account is refused regardless of its roles.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        return $this->hasAnyRole(PermissionRegistry::adminRoles());
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(PermissionRegistry::SUPER_ADMIN);
    }

    /**
     * Hard denial of Super-Admin-only permissions.
     *
     * This guard lives here rather than in a `Gate::before` callback because
     * spatie/laravel-permission registers its OWN before callback, and
     * Laravel returns the first non-null before result. Ordering between two
     * before callbacks is not something to depend on, so the check belongs at
     * the point permission is actually resolved — where it holds however the
     * question is asked: `can()`, `hasPermissionTo()`, a policy, or a Blade
     * directive.
     *
     * Defence in depth behind the role matrix: even a mis-seeded or
     * deliberately rogue custom role cannot grant these.
     */
    public function hasPermissionTo($permission, ?string $guardName = null): bool
    {
        $name = is_string($permission) ? $permission : ($permission->name ?? null);

        if ($name !== null
            && in_array($name, PermissionRegistry::superAdminOnly(), true)
            && ! $this->hasRole(PermissionRegistry::SUPER_ADMIN)) {
            return false;
        }

        return $this->spatieHasPermissionTo($permission, $guardName);
    }

    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(UserSession::class);
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
