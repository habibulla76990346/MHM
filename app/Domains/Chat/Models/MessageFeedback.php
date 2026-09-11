<?php

namespace App\Domains\Chat\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageFeedback extends Model
{
    protected $table = 'message_feedback';

    public const UP = 1;

    public const DOWN = -1;

    protected $fillable = ['message_id', 'user_id', 'rating', 'comment'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'message_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
