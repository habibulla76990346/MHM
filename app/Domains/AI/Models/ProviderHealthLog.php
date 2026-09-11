<?php

namespace App\Domains\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderHealthLog extends Model
{
    protected $fillable = [
        'provider_id', 'model_id', 'checked_at', 'success', 'latency_ms', 'error_class', 'http_status',
    ];

    protected function casts(): array
    {
        return ['checked_at' => 'datetime', 'success' => 'boolean'];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'provider_id');
    }
}
