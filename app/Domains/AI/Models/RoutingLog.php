<?php

namespace App\Domains\AI\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Why one request went where it did (§14).
 *
 * `candidates` holds every model considered and the reason each was rejected,
 * so "why did this go to the expensive model?" has an answer six months later.
 */
class RoutingLog extends Model
{
    protected $fillable = [
        'uuid', 'user_id', 'request_type', 'routing_mode', 'capability_required',
        'candidates', 'selected_provider_id', 'selected_model_id', 'fallback_depth',
        'decision_reason', 'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'capability_required' => 'array',
            'candidates' => 'array',
            'decided_at' => 'datetime',
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

    public function selectedModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'selected_model_id');
    }

    public function selectedProvider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'selected_provider_id');
    }

    /** @return array<int, array<string, mixed>> the ones that were ruled out */
    public function rejectedCandidates(): array
    {
        return array_values(array_filter(
            (array) $this->candidates,
            fn ($c) => ($c['eligible'] ?? false) === false,
        ));
    }
}
