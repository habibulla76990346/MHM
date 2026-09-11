<?php

namespace App\Domains\Files\Models;

use Illuminate\Database\Eloquent\Model;

class FileScanResult extends Model
{
    protected $fillable = ['file_id', 'scanner_key', 'verdict', 'details', 'duration_ms', 'scanned_at'];

    protected function casts(): array
    {
        return ['scanned_at' => 'datetime'];
    }
}
