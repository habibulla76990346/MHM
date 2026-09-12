<?php

namespace App\Domains\Notifications\Models;

use App\Domains\Diagnostics\Support\Redactor;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * That a notification was sent — never what it said.
 *
 * The audit trail answers "did the renewal notice for invoice 42 go out, and
 * did it fail?". It deliberately cannot answer "what did it say", because the
 * rendered body carries a payment link, an amount and a customer's name, and
 * an audit table is read by more people than an inbox is.
 *
 * The error column is SCRUBBED on write: a mail driver's exception routinely
 * carries the SMTP connection string, password included.
 */
class NotificationDelivery extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    /** Deliberately not sent: the event is switched off, or there is nobody to send to. */
    public const STATUS_SUPPRESSED = 'suppressed';

    public const STATUSES = [
        self::STATUS_QUEUED => 'Queued',
        self::STATUS_SENT => 'Sent',
        self::STATUS_FAILED => 'Failed',
        self::STATUS_SUPPRESSED => 'Not sent',
    ];

    protected $fillable = [
        'uuid', 'event_key', 'channel', 'user_id', 'status',
        'reference_type', 'reference_id', 'sent_at', 'failed_at', 'error',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $d) => $d->uuid ??= (string) Str::uuid());

        // On the model, not at the call site: a scrub that has to be
        // remembered is a scrub that gets forgotten.
        static::saving(function (self $delivery) {
            if ($delivery->error !== null) {
                $delivery->error = Str::limit(Redactor::scrub((string) $delivery->error), 480);
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
