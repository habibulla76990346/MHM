<?php

namespace App\Domains\Voice\Models;

use App\Domains\AI\Models\AiModel;
use App\Domains\Chat\Models\Message;
use App\Domains\Files\Models\File;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * One piece of audio turned into text, or one piece of text turned into audio
 * (§18).
 */
class VoiceJob extends Model
{
    use SoftDeletes;

    public const TRANSCRIPTION = 'transcription';

    public const SPEECH = 'speech';

    public const QUEUED = 'queued';

    public const WORKING = 'working';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    protected $fillable = [
        'uuid', 'user_id', 'kind', 'model_id', 'file_id', 'message_id',
        'text', 'language', 'seconds', 'characters', 'status',
        'failure_reason', 'credit_cost', 'started_at', 'completed_at', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'seconds' => 'decimal:2',
            'credit_cost' => 'decimal:6',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $job) {
            $job->uuid ??= (string) Str::uuid();
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

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'message_id');
    }

    public function isWorking(): bool
    {
        return in_array($this->status, [self::QUEUED, self::WORKING], true);
    }

    public function isCompleted(): bool
    {
        return $this->status === self::COMPLETED;
    }

    /** Playable audio still on disk. */
    public function isPlayable(): bool
    {
        return $this->kind === self::SPEECH && $this->isCompleted() && $this->file_id !== null;
    }

    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->getKey());
    }
}
