<?php

namespace App\Domains\Knowledge\Models;

use App\Domains\Chat\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Why the model was told what it was told.
 *
 * The same reason `routing_logs` exists. Chunk ids and scores, never the chunk
 * text: the text is one join away, and duplicating it here would double the
 * storage of the largest table in the system.
 */
class RetrievalLog extends Model
{
    protected $fillable = [
        'message_id', 'knowledge_base_id', 'user_id', 'candidates', 'returned',
        'top_score', 'chunk_ids', 'scores', 'took_ms', 'retrieved_at',
    ];

    protected function casts(): array
    {
        return [
            'chunk_ids' => 'array',
            'scores' => 'array',
            'candidates' => 'integer',
            'returned' => 'integer',
            'top_score' => 'float',
            'took_ms' => 'integer',
            'retrieved_at' => 'datetime',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'message_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
