<?php

namespace App\Domains\Payments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GatewayHealthLog extends Model
{
    protected $fillable = ['gateway_id', 'checked_at', 'success', 'latency_ms', 'error_class'];

    protected function casts(): array
    {
        return ['checked_at' => 'datetime', 'success' => 'boolean'];
    }

    public function gateway(): BelongsTo
    {
        return $this->belongsTo(PaymentGatewayRecord::class, 'gateway_id');
    }
}
