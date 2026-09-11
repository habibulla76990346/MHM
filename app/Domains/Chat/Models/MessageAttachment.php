<?php

namespace App\Domains\Chat\Models;

use App\Domains\Files\Models\File;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class MessageAttachment extends Model
{
    protected $table = 'chat_message_attachments';

    protected $fillable = ['message_id', 'file_id', 'kind'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'message_id');
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class, 'file_id');
    }

    /** Bytes from the private disk, or null if the file has gone or was quarantined. */
    public function contents(): ?string
    {
        $file = $this->file;

        if (! $file || $file->quarantined_at !== null) {
            return null;
        }

        return Storage::disk($file->disk)->get($file->path);
    }
}
