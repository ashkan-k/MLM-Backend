<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmVisitLog extends Model
{
    protected $fillable = [
        'crm_visit_id',
        'user_id',
        'body',
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(CrmVisit::class, 'crm_visit_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
