<?php

namespace App\Domains\Files\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class File extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'uuid', 'user_id', 'disk', 'path', 'stored_name', 'original_name',
        'detected_mime', 'declared_mime', 'extension', 'size_bytes', 'checksum',
        'purpose', 'scan_status', 'scan_verdict', 'scanner_key',
        'quarantined_at', 'expires_at',
    ];

    /** The storage path is never exposed — files are addressed by uuid only. */
    protected $hidden = ['path', 'stored_name', 'disk'];

    protected function casts(): array
    {
        return [
            'quarantined_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $file) {
            $file->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function scanResults(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(FileScanResult::class);
    }

    public function accessLogs(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(FileAccessLog::class);
    }

    public function isQuarantined(): bool
    {
        return $this->quarantined_at !== null;
    }
}
