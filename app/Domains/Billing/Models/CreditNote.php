<?php

namespace App\Domains\Billing\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * The correction mechanism for an issued invoice.
 *
 * Accounting does not permit editing a document that has been sent, so neither
 * does this system: a mistake produces a credit note reversing part or all of
 * the invoice, and a fresh invoice if a replacement is needed. Both documents
 * survive, which is exactly what an auditor expects to see.
 */
class CreditNote extends Model
{
    protected $fillable = [
        'uuid', 'invoice_id', 'number', 'reason', 'amount', 'tax_amount',
        'currency', 'tax_snapshot', 'issued_by', 'issued_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:6',
            'tax_amount' => 'decimal:6',
            'tax_snapshot' => 'array',
            'issued_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $n) => $n->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }
}
