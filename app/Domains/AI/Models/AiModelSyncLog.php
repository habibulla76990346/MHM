<?php

namespace App\Domains\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiModelSyncLog extends Model
{
    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'provider_id', 'started_at', 'finished_at', 'status', 'models_added',
        'models_updated', 'models_deprecated', 'error_message', 'raw_response_digest', 'triggered_by',
    ];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'provider_id');
    }

    public function summary(): string
    {
        if ($this->status === self::STATUS_FAILED) {
            return 'Failed';
        }

        if ($this->status === self::STATUS_RUNNING) {
            return 'Running';
        }

        return sprintf(
            '%d new, %d updated, %d deprecated',
            $this->models_added,
            $this->models_updated,
            $this->models_deprecated,
        );
    }
}
