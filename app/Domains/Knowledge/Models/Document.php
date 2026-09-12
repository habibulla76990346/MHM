<?php

namespace App\Domains\Knowledge\Models;

use App\Domains\Files\Models\File;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A file that has been taken into a knowledge base.
 *
 * EVERY STATUS IS SHOWN TO THE CUSTOMER, with the reason when it failed.
 * "Processing" with no detail and no end is what makes somebody upload the
 * same file four times and then email support.
 */
class Document extends Model
{
    use SoftDeletes;

    public const STATUS_PENDING = 'pending';

    public const STATUS_EXTRACTING = 'extracting';

    public const STATUS_EXTRACTED = 'extracted';

    public const STATUS_EMBEDDING = 'embedding';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    /** What the customer reads, in words rather than machine states. */
    public const STATUSES = [
        self::STATUS_PENDING => 'Waiting',
        self::STATUS_EXTRACTING => 'Reading the file',
        self::STATUS_EXTRACTED => 'Read — preparing to index',
        self::STATUS_EMBEDDING => 'Indexing',
        self::STATUS_READY => 'Ready to search',
        self::STATUS_FAILED => 'Could not be used',
    ];

    protected $fillable = [
        'uuid', 'knowledge_base_id', 'file_id', 'user_id', 'title', 'status',
        'extractor_key', 'character_count', 'chunk_count', 'token_estimate',
        'failure_reason', 'extracted_at', 'embedded_at',
    ];

    protected function casts(): array
    {
        return [
            'character_count' => 'integer',
            'chunk_count' => 'integer',
            'token_estimate' => 'integer',
            'extracted_at' => 'datetime',
            'embedded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $d) => $d->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function knowledgeBase(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBase::class);
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(DocumentChunk::class);
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    public function hasFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /** Still moving. Used by the screen that decides whether to keep polling. */
    public function isWorking(): bool
    {
        return in_array($this->status, [
            self::STATUS_PENDING, self::STATUS_EXTRACTING,
            self::STATUS_EXTRACTED, self::STATUS_EMBEDDING,
        ], true);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
