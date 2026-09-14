<?php

namespace App\Domains\Diagnostics\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** One execution of the diagnostic catalogue. */
class DiagnosticRun extends Model
{
    public const INSTALL = 'install';

    public const MANUAL = 'manual';

    public const SCHEDULED = 'scheduled';

    public const EVENT = 'event';

    protected $fillable = [
        'uuid', 'trigger', 'deployment_mode', 'overall_status',
        'green', 'yellow', 'red', 'grey', 'run_by', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $run) => $run->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function results(): HasMany
    {
        return $this->hasMany(DiagnosticResult::class, 'run_id');
    }

    public function runBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'run_by');
    }
}
