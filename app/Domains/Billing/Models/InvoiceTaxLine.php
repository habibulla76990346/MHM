<?php

namespace App\Domains\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One tax component, frozen onto one invoice.
 *
 * There is deliberately NO foreign key back to `tax_rates`. A reference would
 * mean the invoice re-reads the rate when it is displayed, and the day that
 * rate changed every historical invoice would change with it. The name and the
 * number are copies, and the row they came from may be edited or deleted
 * without this document moving.
 */
class InvoiceTaxLine extends Model
{
    protected $fillable = [
        'invoice_id', 'component_name', 'component_code', 'rate_percent',
        'taxable_amount', 'tax_amount', 'jurisdiction_name', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'rate_percent' => 'decimal:5',
            'taxable_amount' => 'decimal:6',
            'tax_amount' => 'decimal:6',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }
}
