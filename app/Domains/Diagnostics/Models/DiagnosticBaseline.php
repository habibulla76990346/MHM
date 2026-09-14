<?php

namespace App\Domains\Diagnostics\Models;

use Illuminate\Database\Eloquent\Model;

/** What each check looked like last time, so a CHANGE can be recognised. */
class DiagnosticBaseline extends Model
{
    protected $fillable = [
        'check_key', 'last_status', 'last_severity', 'consecutive_failures',
        'changed_at', 'last_seen_at', 'notified_at',
    ];

    protected function casts(): array
    {
        return [
            'changed_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'notified_at' => 'datetime',
        ];
    }
}
