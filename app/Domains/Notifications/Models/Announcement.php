<?php

namespace App\Domains\Notifications\Models;

use App\Domains\Billing\Models\Plan;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * An announcement, offer, maintenance notice or system update (§22).
 *
 * AN ANNOUNCEMENT IS A MESSAGE, NOT A BANNER. Aziv AI already has a banner:
 * `Banner` owns the strip at the top of a page, its priority and its
 * per-browser dismissal (Phase 2). A second strip would mean two audiences,
 * two dismissals, and two ways to push content below the fold on a phone.
 *
 * What §22 asks for and a banner cannot do is REACH PEOPLE — an audience
 * defined by their subscription, delivered through the one notifier, in the
 * app and, when the owner chooses, by email. So this is composed, targeted,
 * and sent.
 *
 * LEVEL IS A TOKEN NAME, NEVER A COLOUR, so a rebrand does not leave last
 * season's amber behind.
 */
class Announcement extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_SENT = 'sent';

    public const STATUSES = [
        self::STATUS_DRAFT => 'Draft',
        self::STATUS_SCHEDULED => 'Scheduled',
        self::STATUS_SENT => 'Sent',
    ];

    public const LEVELS = [
        'info' => 'Information',
        'success' => 'Good news',
        'warning' => 'Warning',
        'critical' => 'Urgent',
    ];

    /**
     * Who it goes to. A CLOSED SET: an audience expressed as a free query
     * would be a way to mail anybody anything from a screen that is not the
     * user list.
     */
    public const AUDIENCE_EVERYONE = 'everyone';

    public const AUDIENCE_SUBSCRIBERS = 'subscribers';

    public const AUDIENCE_TRIALING = 'trialing';

    public const AUDIENCE_PAST_DUE = 'past_due';

    public const AUDIENCE_PLAN = 'plan';

    public const AUDIENCES = [
        self::AUDIENCE_EVERYONE => 'Everyone with an account',
        self::AUDIENCE_SUBSCRIBERS => 'Customers on a live subscription',
        self::AUDIENCE_TRIALING => 'Customers on a trial',
        self::AUDIENCE_PAST_DUE => 'Customers whose payment is overdue',
        self::AUDIENCE_PLAN => 'Customers on one particular plan',
    ];

    /**
     * `sent_at` and `recipient_count` are NOT fillable.
     *
     * They are the record of what happened, written by the service with
     * `forceFill` — and `sent_at` is the guard that stops an announcement
     * being mailed twice. A guard that a form could set is not a guard.
     */
    protected $fillable = [
        'uuid', 'title', 'body', 'level', 'audience', 'audience_plan_id',
        'status', 'send_email', 'send_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'send_email' => 'boolean',
            'send_at' => 'datetime',
            'sent_at' => 'datetime',
            'recipient_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $a) => $a->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'audience_plan_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isSent(): bool
    {
        return $this->sent_at !== null;
    }

    /**
     * Whether the scheduler should send this now.
     *
     * `sent_at` is checked as well as the status because it is the real guard:
     * it is stamped before sending begins, so a run that overlaps with the
     * previous one finds it already set and does nothing.
     */
    public function isDue(): bool
    {
        return ! $this->isSent()
            && $this->status === self::STATUS_SCHEDULED
            && ($this->send_at === null || $this->send_at->isPast());
    }
}
