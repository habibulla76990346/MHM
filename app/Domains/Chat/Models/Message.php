<?php

namespace App\Domains\Chat\Models;

use App\Domains\AI\Models\AiModel;
use App\Domains\AI\Models\AiProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Message extends Model
{
    protected $table = 'chat_messages';

    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    public const ROLE_SYSTEM = 'system';

    public const STATUS_PENDING = 'pending';

    public const STATUS_STREAMING = 'streaming';

    public const STATUS_COMPLETE = 'complete';

    public const STATUS_STOPPED = 'stopped';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'uuid', 'conversation_id', 'role', 'content', 'status', 'provider_id',
        'model_id', 'parent_message_id', 'regenerated_from_id', 'error_class',
        'input_tokens', 'output_tokens', 'latency_ms', 'finished_at',
    ];

    protected function casts(): array
    {
        return ['finished_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'model_id');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'provider_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(MessageAttachment::class, 'message_id');
    }

    public function feedback(): HasOne
    {
        return $this->hasOne(MessageFeedback::class, 'message_id')
            ->where('user_id', auth()->id() ?? 0);
    }

    /** The answer that replaced this one, if it was regenerated. */
    public function replacement(): HasOne
    {
        return $this->hasOne(self::class, 'regenerated_from_id');
    }

    /**
     * The answer THIS one replaced.
     *
     * Used when rebuilding context for a regeneration: history must be the
     * conversation as it stood before that answer existed, so the cut-off is
     * the original row, not this replacement.
     */
    public function replacementSource(): ?self
    {
        return $this->regenerated_from_id
            ? self::find($this->regenerated_from_id)
            : null;
    }

    public function isFromUser(): bool
    {
        return $this->role === self::ROLE_USER;
    }

    public function isInProgress(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_STREAMING], true);
    }

    /**
     * A stopped reply keeps whatever arrived before the customer stopped it.
     * Discarding it would throw away work they have already been charged for.
     */
    public function wasStopped(): bool
    {
        return $this->status === self::STATUS_STOPPED;
    }

    public function totalTokens(): int
    {
        return $this->input_tokens + $this->output_tokens;
    }

    /** @return array<int, array{type: string, url?: string, data?: string, mime?: string}> */
    public function attachmentPayload(): array
    {
        $payload = [];

        foreach ($this->attachments()->with('file')->get() as $attachment) {
            $file = $attachment->file;

            if (! $file) {
                continue;
            }

            // Files live on the PRIVATE disk with no URL (Addendum H), so an
            // image is sent to a provider as inline bytes rather than as a
            // link they would have to be able to fetch.
            $bytes = $attachment->contents();

            if ($bytes === null) {
                continue;
            }

            $payload[] = [
                'type' => 'image',
                'mime' => $file->detected_mime,
                'data' => base64_encode($bytes),
                'url' => 'data:'.$file->detected_mime.';base64,'.base64_encode($bytes),
            ];
        }

        return $payload;
    }
}
