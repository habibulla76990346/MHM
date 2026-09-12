<?php

namespace App\Domains\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One limit on one plan (§19).
 *
 * A row rather than a column, so the owner inventing a new limit next month is
 * a data entry task and not a migration.
 */
class PlanFeature extends Model
{
    public const HARD = 'hard';

    public const SOFT = 'soft';

    public const UNLIMITED = 'unlimited';

    /** The limits the platform itself reads. Others are free-form. */
    public const KNOWN = [
        'messages_per_day' => 'Messages per day',
        'messages_per_month' => 'Messages per month',
        'max_attachments' => 'Attachments per message',
        'max_file_size_kb' => 'Largest upload (KB)',
        'storage_mb' => 'Total storage (MB)',
        'image_credits_per_period' => 'Image generations per period',
        'conversation_history_days' => 'Conversation history kept (days)',
        'max_context_messages' => 'Messages of context sent',
        'priority_routing' => 'Priority routing',
        'can_pin_model' => 'May choose a specific model',
        'api_access' => 'API access',
    ];

    protected $fillable = ['plan_id', 'key', 'value', 'limit_type'];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    public function isUnlimited(): bool
    {
        return $this->limit_type === self::UNLIMITED;
    }

    /**
     * The limit as a number, or null when there is no ceiling.
     *
     * Null and zero are DIFFERENT: null is "no limit", zero is "none allowed".
     * Collapsing them is how a plan meant to forbid something ends up
     * permitting everything.
     */
    public function numericLimit(): ?float
    {
        return $this->isUnlimited() || $this->value === null ? null : (float) $this->value;
    }

    public function isEnabled(): bool
    {
        return in_array(strtolower((string) $this->value), ['1', 'true', 'yes', 'on'], true);
    }
}
