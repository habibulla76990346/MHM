<?php

namespace App\Domains\Knowledge\Models;

use App\Domains\Billing\Models\Plan;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Permission to search a shared knowledge base.
 *
 * By named customer or by whole plan, and nothing else. Who granted it is
 * recorded, because "who gave this customer access to the internal handbook?"
 * is a question that gets asked after the fact.
 */
class KnowledgeBaseGrant extends Model
{
    protected $fillable = ['knowledge_base_id', 'user_id', 'plan_id', 'granted_by'];

    public function knowledgeBase(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBase::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }
}
