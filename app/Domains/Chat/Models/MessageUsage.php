<?php

namespace App\Domains\Chat\Models;

use App\Domains\AI\Models\ApiUsageLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one answer consumed, kept beside the answer itself (§15, §21).
 *
 * The same numbers exist in `api_usage_logs`, which is the analytics table.
 * This row is the customer-facing half: "this reply cost you N credits" must
 * still be answerable when usage logs have been pruned by the retention
 * setting, and must not require scanning the highest-volume table in the
 * system to show one message.
 */
class MessageUsage extends Model
{
    protected $table = 'message_usage';

    protected $fillable = [
        'message_id', 'usage_log_id', 'input_tokens', 'output_tokens', 'credit_cost',
    ];

    protected function casts(): array
    {
        return ['credit_cost' => 'decimal:6'];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'message_id');
    }

    public function usageLog(): BelongsTo
    {
        return $this->belongsTo(ApiUsageLog::class, 'usage_log_id');
    }
}
