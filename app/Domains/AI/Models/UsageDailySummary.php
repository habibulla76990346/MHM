<?php

namespace App\Domains\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UsageDailySummary extends Model
{
    protected $fillable = [
        'summary_date', 'provider_id', 'model_id', 'requests', 'failures',
        'input_tokens', 'output_tokens', 'provider_cost', 'provider_currency',
        'provider_cost_base', 'credit_cost', 'p50_latency_ms',
    ];

    protected function casts(): array
    {
        return [
            'summary_date' => 'date',
            'provider_cost' => 'decimal:6',
            'provider_cost_base' => 'decimal:6',
            'credit_cost' => 'decimal:6',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'provider_id');
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'model_id');
    }

    /** What the owner actually keeps, in their own currency. */
    public function margin(): float
    {
        return (float) $this->credit_cost - (float) $this->provider_cost_base;
    }
}
