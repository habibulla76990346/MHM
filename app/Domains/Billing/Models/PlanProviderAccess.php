<?php

namespace App\Domains\Billing\Models;

use App\Domains\AI\Models\AiProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanProviderAccess extends Model
{
    protected $table = 'plan_provider_access';

    protected $fillable = ['plan_id', 'ai_provider_id', 'is_allowed'];

    protected function casts(): array
    {
        return ['is_allowed' => 'boolean'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'ai_provider_id');
    }
}
