<?php

namespace App\Domains\Billing\Models;

use App\Domains\AI\Models\AiModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanModelAccess extends Model
{
    protected $table = 'plan_model_access';

    protected $fillable = ['plan_id', 'ai_model_id', 'is_allowed'];

    protected function casts(): array
    {
        return ['is_allowed' => 'boolean'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'ai_model_id');
    }
}
