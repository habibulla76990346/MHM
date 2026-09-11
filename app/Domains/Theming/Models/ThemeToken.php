<?php

namespace App\Domains\Theming\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ThemeToken extends Model
{
    protected $fillable = ['theme_id', 'scope', 'mode', 'token_group', 'token_key', 'token_value'];

    public function theme(): BelongsTo
    {
        return $this->belongsTo(Theme::class);
    }
}
