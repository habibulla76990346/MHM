<?php

namespace App\Domains\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An administrator's description of an API Aziv AI has never seen (§10).
 *
 * This is what `CustomHttpAdapter` reads: where the endpoint is, what shape to
 * send, and where in the answer the text lives. It is the difference between
 * "we support that provider" and "a developer must add it".
 */
class CustomProviderMapping extends Model
{
    protected $fillable = [
        'provider_id', 'capability', 'http_method', 'endpoint_path',
        'request_template', 'response_mapping', 'stream_format', 'headers_template',
    ];

    protected function casts(): array
    {
        return [
            'request_template' => 'array',
            'response_mapping' => 'array',
            'headers_template' => 'array',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'provider_id');
    }
}
