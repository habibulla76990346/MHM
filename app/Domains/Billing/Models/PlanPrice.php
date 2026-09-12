<?php

namespace App\Domains\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanPrice extends Model
{
    protected $fillable = ['plan_id', 'currency', 'amount', 'is_active'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:6', 'is_active' => 'boolean'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }
}
