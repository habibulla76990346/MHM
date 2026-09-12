<?php

namespace App\Domains\Payments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One webhook delivery, recorded before it is acted on.
 *
 * The unique index on (gateway, event id) is the replay guard: a gateway
 * retries until acknowledged, and a retry must not grant a second set of
 * credits. Inserting the row IS the claim on that event — the insert either
 * succeeds, and this delivery does the work, or it fails and the delivery is a
 * no-op.
 *
 * Rejected deliveries are recorded too. A run of signature failures is either
 * a misconfigured secret or somebody probing the endpoint, and both are things
 * an owner needs to see.
 */
class PaymentWebhookEvent extends Model
{
    protected $fillable = [
        'uuid', 'gateway_id', 'event_id', 'event_type', 'raw_payload',
        'signature_valid', 'received_at', 'processed_at', 'result', 'attempts',
    ];

    protected function casts(): array
    {
        return [
            'raw_payload' => 'array',
            'signature_valid' => 'boolean',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $e) => $e->uuid ??= (string) Str::uuid());
    }

    public function gateway(): BelongsTo
    {
        return $this->belongsTo(PaymentGatewayRecord::class, 'gateway_id');
    }
}
