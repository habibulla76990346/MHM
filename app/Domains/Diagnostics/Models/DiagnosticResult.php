<?php

namespace App\Domains\Diagnostics\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One finding, as it stood at the time of a run.
 *
 * Everything textual here was scrubbed by `CheckResult` before it was written:
 * there is no path into this table that could store an unredacted value.
 */
class DiagnosticResult extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'run_id', 'check_key', 'title', 'category', 'status', 'severity',
        'responsibility', 'technical_reason', 'recommended_action', 'admin_action',
        'requires_hosting_support', 'support_wording', 'log_reference',
        'duration_ms', 'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'requires_hosting_support' => 'boolean',
            'checked_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(DiagnosticRun::class, 'run_id');
    }
}
