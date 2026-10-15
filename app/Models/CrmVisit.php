<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmVisit extends Model
{
    protected $fillable = [
        'user_id',
        'visited_on',
        'activity_type',
        'place',
        'person_name',
        'national_id',
        'mobile',
        'result',
        'status',
        'remind_at',
        'notify_in_app',
        'notify_sms',
        'notified_at',
        'sms_sent_at',
        'reminded_at',
    ];

    protected function casts(): array
    {
        return [
            'visited_on' => 'date',
            'remind_at' => 'datetime',
            'notified_at' => 'datetime',
            'sms_sent_at' => 'datetime',
            'reminded_at' => 'datetime',
            'notify_in_app' => 'boolean',
            'notify_sms' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
