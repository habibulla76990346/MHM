<?php

namespace App\Domains\Billing\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * An invoice (§20, Addendum F).
 *
 * IMMUTABLE ONCE ISSUED, enforced here rather than in a form. Every figure and
 * every name on it is a COPY taken at issue time — the business address, the
 * customer's details, each tax component's name, rate and amount. Nothing is
 * looked up again when it is displayed, which is the only way the owner's
 * requirement can hold: changing a tax rate tomorrow must not alter a document
 * already sent, and reissuing a March invoice must produce the March document.
 *
 * Corrections are a credit note and a new invoice, the way accounting requires.
 */
class Invoice extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_ISSUED = 'issued';

    public const STATUS_PAID = 'paid';

    public const STATUS_VOID = 'void';

    /**
     * The only columns that may move after issue.
     *
     * Being paid, or being voided, is something that HAPPENS TO an invoice; it
     * does not change what the invoice says. Everything else is frozen.
     */
    private const MUTABLE_AFTER_ISSUE = ['status', 'paid_at', 'updated_at'];

    protected $fillable = [
        'uuid', 'user_id', 'subscription_id', 'renewal_period_start', 'number', 'status', 'subtotal',
        'discount_total', 'tax_total', 'total', 'currency', 'exchange_rate_used',
        'base_total', 'supplier_legal_name', 'supplier_address', 'supplier_tax_number',
        'customer_name', 'customer_address', 'customer_country', 'customer_state',
        'customer_tax_number', 'place_of_supply', 'service_code', 'pricing_mode',
        'is_export', 'tax_note', 'issued_at', 'due_at', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:6',
            'discount_total' => 'decimal:6',
            'tax_total' => 'decimal:6',
            'total' => 'decimal:6',
            'base_total' => 'decimal:6',
            'exchange_rate_used' => 'decimal:8',
            'is_export' => 'boolean',
            'renewal_period_start' => 'datetime',
            'issued_at' => 'datetime',
            'due_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $i) => $i->uuid ??= (string) Str::uuid());

        static::updating(function (self $invoice) {
            // Read from the ORIGINAL row: an invoice being issued right now is
            // still a draft as far as this guard is concerned, or issuing it
            // would be blocked by its own new state.
            if ($invoice->getOriginal('issued_at') === null) {
                return;
            }

            $changed = array_diff(array_keys($invoice->getDirty()), self::MUTABLE_AFTER_ISSUE);

            if ($changed !== []) {
                throw new RuntimeException(
                    'Invoice '.$invoice->number.' has been issued and cannot be changed ('
                    .implode(', ', $changed).'). Issue a credit note instead.',
                );
            }
        });

        static::deleting(function (self $invoice) {
            if ($invoice->issued_at !== null) {
                throw new RuntimeException('An issued invoice cannot be deleted. Void it and issue a credit note.');
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class, 'invoice_id')->orderBy('sort_order');
    }

    public function taxLines(): HasMany
    {
        return $this->hasMany(InvoiceTaxLine::class, 'invoice_id')->orderBy('sort_order');
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class, 'invoice_id');
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    /** Issued, not yet paid, and not credited away — something to chase. */
    public function isOutstanding(): bool
    {
        return $this->status === self::STATUS_ISSUED;
    }

    public function isIssued(): bool
    {
        return $this->issued_at !== null;
    }

    public function isEditable(): bool
    {
        return ! $this->isIssued();
    }

    /** What is still owed after any credit notes. */
    public function outstanding(): float
    {
        return max(0, (float) $this->total - (float) $this->creditNotes()->sum('amount'));
    }
}
