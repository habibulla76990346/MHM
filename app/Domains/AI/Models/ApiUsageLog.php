<?php

namespace App\Domains\AI\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One provider call, with what it cost and what it earned (§13, §21).
 *
 * `provider_cost` is in the provider's OWN currency, recorded alongside it.
 * Converting at write time would freeze one day's rate into history; using
 * today's rate at read time would rewrite last quarter's margin every time the
 * rupee moves. Both are wrong in the same way — the conversion belongs at the
 * rate that applied on the day.
 */
class ApiUsageLog extends Model
{
    protected $fillable = [
        'uuid', 'user_id', 'provider_id', 'model_id', 'credential_id', 'routing_log_id',
        'capability', 'input_tokens', 'output_tokens', 'total_tokens', 'latency_ms',
        'http_status', 'error_class', 'provider_cost', 'provider_currency',
        'credit_cost', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'provider_cost' => 'decimal:10',
            'credit_cost' => 'decimal:6',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $log) => $log->uuid ??= (string) Str::uuid());
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'provider_id');
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'model_id');
    }

    public function succeeded(): bool
    {
        return $this->error_class === null;
    }
}
