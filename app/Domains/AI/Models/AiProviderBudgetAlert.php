<?php

namespace App\Domains\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiProviderBudgetAlert extends Model
{
    protected $fillable = ['budget_id', 'threshold_hit', 'notified_at', 'action_taken'];

    protected function casts(): array
    {
        return ['notified_at' => 'datetime'];
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(AiProviderBudget::class, 'budget_id');
    }
}
