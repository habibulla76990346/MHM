<?php

namespace App\Domains\Chat\Models;

use App\Domains\AI\Models\AiModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Conversation extends Model
{
    use SoftDeletes;

    protected $table = 'chat_conversations';

    public const ROUTING_AUTO = 'auto';

    public const ROUTING_SPECIFIC_MODEL = 'specific_model';

    protected $fillable = [
        'uuid', 'user_id', 'title', 'persona_id', 'pinned_model_id',
        'routing_mode', 'is_archived', 'last_message_at',
    ];

    protected function casts(): array
    {
        return ['is_archived' => 'boolean', 'last_message_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $c) => $c->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id');
    }

    public function pinnedModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'pinned_model_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'conversation_id')->orderBy('created_at')->orderBy('id');
    }

    /**
     * What the customer actually sees.
     *
     * A regenerated answer supersedes the one it replaced, but BOTH rows stay
     * — §15 requires regenerate to preserve history. The superseded row is
     * filtered out of the visible thread rather than deleted.
     */
    public function visibleMessages(): HasMany
    {
        return $this->messages()
            ->whereNotIn('id', function ($query) {
                $query->select('regenerated_from_id')
                    ->from('chat_messages')
                    ->whereNotNull('regenerated_from_id');
            });
    }

    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->getKey());
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_archived', false);
    }

    /**
     * Name a conversation from its first message.
     *
     * Deliberately not an AI call: titling every conversation with a model
     * would double the number of requests a chat costs, for something the
     * first few words already answer.
     */
    public function titleFrom(string $text): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($text)) ?? '');

        if ($text === '') {
            return __('New chat');
        }

        return Str::limit($text, 60, '…');
    }

    public function touchLastMessage(): void
    {
        $this->forceFill(['last_message_at' => now()])->save();
    }
}
