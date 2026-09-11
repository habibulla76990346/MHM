<?php

namespace App\Domains\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a unit of this model costs, and what it sells for, between two dates
 * (blueprint §13).
 */
class AiModelPrice extends Model
{
    public const UNITS = [
        'per_1k_input' => 'Per 1,000 input tokens',
        'per_1k_output' => 'Per 1,000 output tokens',
        'per_image' => 'Per image',
        'per_second' => 'Per second of audio',
        'per_request' => 'Per request',
    ];

    protected $fillable = [
        'model_id', 'unit', 'provider_cost', 'currency', 'credit_cost',
        'effective_from', 'effective_until',
    ];

    protected function casts(): array
    {
        return [
            'provider_cost' => 'decimal:8',
            'credit_cost' => 'decimal:6',
            'effective_from' => 'datetime',
            'effective_until' => 'datetime',
        ];
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'model_id');
    }

    /** The gap between what it costs and what it sells for (§21). */
    public function margin(): float
    {
        return (float) $this->credit_cost - (float) $this->provider_cost;
    }

    public function isCurrent(): bool
    {
        return $this->effective_from->isPast()
            && ($this->effective_until === null || $this->effective_until->isFuture());
    }
}
