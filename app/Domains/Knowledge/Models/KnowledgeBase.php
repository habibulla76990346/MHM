<?php

namespace App\Domains\Knowledge\Models;

use App\Domains\AI\Models\AiModel;
use App\Domains\Billing\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * A searchable collection of documents (§17).
 *
 * WHO MAY SEARCH IT IS PART OF WHAT IT IS. §17 requires that "sensitive file
 * access must follow role-based permissions and privacy rules", and the way
 * that is kept true is that there is exactly one method — `isReadableBy()` —
 * and every path into retrieval goes through it. A base with no grant reaches
 * nobody; there is no "everyone" value to set by accident.
 */
class KnowledgeBase extends Model
{
    use SoftDeletes;

    /** A customer's own. Visible to them and to nobody else. */
    public const SCOPE_PERSONAL = 'personal';

    /** Created by an administrator and granted explicitly. */
    public const SCOPE_SHARED = 'shared';

    public const SCOPES = [
        self::SCOPE_PERSONAL => 'Personal — the customer who owns it',
        self::SCOPE_SHARED => 'Shared — the customers or plans you grant it to',
    ];

    protected $fillable = [
        'uuid', 'name', 'description', 'scope', 'user_id', 'created_by', 'is_active',
        'top_k', 'min_score', 'chunk_size', 'chunk_overlap', 'embedding_model_id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'top_k' => 'integer',
            'min_score' => 'float',
            'chunk_size' => 'integer',
            'chunk_overlap' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $b) => $b->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function embeddingModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'embedding_model_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(DocumentChunk::class);
    }

    public function grants(): HasMany
    {
        return $this->hasMany(KnowledgeBaseGrant::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function isPersonal(): bool
    {
        return $this->scope === self::SCOPE_PERSONAL;
    }

    /**
     * THE ONE PERMISSION DECISION. Everything that retrieves goes through it.
     *
     * A personal base belongs to its owner and to nobody else — not to an
     * administrator either, because "sensitive file access must follow privacy
     * rules" and an admin reading a customer's uploaded documents through the
     * chat interface is exactly what that forbids. Administration of a base is
     * a different thing from searching it, and lives on a different screen.
     *
     * A shared base needs an explicit grant: to this person, or to the plan
     * they are currently on.
     */
    public function isReadableBy(?User $user): bool
    {
        if (! $user || ! $this->is_active) {
            return false;
        }

        if ($this->isPersonal()) {
            return $this->user_id === $user->getKey();
        }

        if ($this->grants()->where('user_id', $user->getKey())->exists()) {
            return true;
        }

        $planId = Subscription::where('user_id', $user->getKey())
            ->live()
            ->latest('id')
            ->value('plan_id');

        return $planId !== null
            && $this->grants()->where('plan_id', $planId)->exists();
    }

    /** Whether this person may add or remove documents. */
    public function isWritableBy(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        // A shared base is the administrator's to curate. A customer granted
        // read access must not be able to put a document into a collection
        // other customers search.
        return $this->isPersonal()
            ? $this->user_id === $user->getKey()
            : ($user->can('knowledge.manage') ?? false);
    }

    /**
     * Every base this person may search.
     *
     * @return Collection<int, KnowledgeBase>
     */
    public static function readableBy(User $user)
    {
        $planId = Subscription::where('user_id', $user->getKey())
            ->live()
            ->latest('id')
            ->value('plan_id');

        return static::query()
            ->active()
            ->where(function (Builder $query) use ($user, $planId) {
                $query->where(fn (Builder $q) => $q
                    ->where('scope', self::SCOPE_PERSONAL)
                    ->where('user_id', $user->getKey()));

                $query->orWhere(fn (Builder $q) => $q
                    ->where('scope', self::SCOPE_SHARED)
                    ->whereHas('grants', fn (Builder $g) => $g
                        ->where('user_id', $user->getKey())
                        ->when($planId, fn (Builder $p) => $p->orWhere('plan_id', $planId))));
            })
            ->orderBy('name')
            ->get();
    }
}
