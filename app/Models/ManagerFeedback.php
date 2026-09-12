<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManagerFeedback extends Model
{
    protected $table = 'manager_feedback';

    protected $fillable = [
        'promotion_request_id',
        'reviewer_user_id',
        'decision',
        'note',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(PromotionRequest::class, 'promotion_request_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_user_id');
    }
}
