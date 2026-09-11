<?php

namespace App\Domains\Files\Models;

use Illuminate\Database\Eloquent\Model;

class FileAccessLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['file_id', 'user_id', 'action', 'ip', 'occurred_at'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }
}
