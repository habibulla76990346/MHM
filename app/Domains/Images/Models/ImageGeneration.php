<?php

namespace App\Domains\Images\Models;

use App\Domains\AI\Models\AiModel;
use App\Domains\Files\Models\File;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * One generated image, and everything about how it came to exist (§16).
 */
class ImageGeneration extends Model
{
    use SoftDeletes;

    public const QUEUED = 'queued';

    public const GENERATING = 'generating';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    /**
     * Sizes the platform speaks.
     *
     * PROVIDER-AGNOSTIC ON PURPOSE. An adapter maps these onto whatever its
     * provider calls them; a request for a size a provider cannot make is the
     * adapter's problem to translate or refuse, not the customer's to know
     * about.
     */
    public const SIZES = [
        '1024x1024' => 'Square',
        '1024x1792' => 'Portrait',
        '1792x1024' => 'Landscape',
    ];

    public const QUALITIES = [
        'standard' => 'Standard',
        'high' => 'Higher detail, costs more',
    ];

    protected $fillable = [
        'uuid', 'user_id', 'model_id', 'routing_log_id', 'file_id',
        'batch_uuid', 'position', 'prompt', 'revised_prompt', 'negative_prompt',
        'size', 'quality', 'style', 'status', 'failure_reason', 'credit_cost',
        'regenerated_from_id', 'started_at', 'completed_at', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'credit_cost' => 'decimal:6',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $generation) {
            $generation->uuid ??= (string) Str::uuid();
            $generation->batch_uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'model_id');
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class, 'file_id');
    }

    /** The generation this one was asked to improve on. */
    public function source(): BelongsTo
    {
        return $this->belongsTo(self::class, 'regenerated_from_id');
    }

    /** Everything generated from this one. */
    public function regenerations(): HasMany
    {
        return $this->hasMany(self::class, 'regenerated_from_id');
    }

    public function isWorking(): bool
    {
        return in_array($this->status, [self::QUEUED, self::GENERATING], true);
    }

    public function isCompleted(): bool
    {
        return $this->status === self::COMPLETED;
    }

    /** A completed generation whose file is still there. */
    public function isViewable(): bool
    {
        return $this->isCompleted() && $this->file_id !== null;
    }

    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->getKey());
    }
}
