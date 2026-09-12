<?php

namespace App\Domains\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceLine extends Model
{
    protected $fillable = [
        'invoice_id', 'description', 'quantity', 'unit_amount',
        'discount_amount', 'line_total', 'service_code', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:6',
            'unit_amount' => 'decimal:6',
            'discount_amount' => 'decimal:6',
            'line_total' => 'decimal:6',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }
}
