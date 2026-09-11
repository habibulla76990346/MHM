<?php

namespace App\Domains\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A spending cap per provider (§10, §21).
 *
 * `action_on_breach` is the owner's decision, not the system's: "warn" keeps
 * the service running and tells them, "block" stops spending. Both are
 * legitimate, and guessing wrong either surprises them with a bill or takes
 * their product down.
 */
class AiProviderBudget extends Model
{
    public const PERIODS = ['daily' => 'Each day', 'monthly' => 'Each month'];

    public const ACTIONS = [
        'warn' => 'Keep working, but alert me',
        'block' => 'Stop using this provider until the next period',
    ];

    protected $fillable = [
        'provider_id', 'period', 'budget_amount', 'currency', 'spent_amount',
        'threshold_percent', 'action_on_breach', 'period_started_at',
    ];

    protected function casts(): array
    {
        return [
            'budget_amount' => 'decimal:4',
            'spent_amount' => 'decimal:4',
            'period_started_at' => 'datetime',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'provider_id');
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(AiProviderBudgetAlert::class, 'budget_id');
    }

    public function percentUsed(): float
    {
        $budget = (float) $this->budget_amount;

        return $budget > 0 ? round((float) $this->spent_amount / $budget * 100, 1) : 0.0;
    }

    public function isExhausted(): bool
    {
        return (float) $this->spent_amount >= (float) $this->budget_amount;
    }

    /** Whether this budget should stop the provider being used right now. */
    public function blocksUse(): bool
    {
        return $this->action_on_breach === 'block' && $this->isExhausted();
    }
}
