<?php

namespace App\Domains\Knowledge\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One searchable passage.
 *
 * The embedding is a packed float32 BLOB — see `Vector` for why — and it is
 * NOT in `$fillable`: a vector is written by the indexing job with its model
 * and dimensions together or not at all, because a vector stored without them
 * cannot be safely compared with anything afterwards.
 */
class DocumentChunk extends Model
{
    protected $fillable = [
        'document_id', 'knowledge_base_id', 'ordinal', 'content',
        'token_estimate', 'locator', 'checksum',
    ];

    protected function casts(): array
    {
        return [
            'ordinal' => 'integer',
            'token_estimate' => 'integer',
            'dimensions' => 'integer',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function knowledgeBase(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBase::class);
    }
}
