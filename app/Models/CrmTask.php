<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmTask extends Model
{
    protected $fillable = [
        'user_id',
        'role_id',
        'title',
        'priority',
        'notes',
        'due_at',
        'notify_in_app',
        'notify_sms',
        'notified_at',
        'sms_sent_at',
        'reminded_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'notified_at' => 'datetime',
            'sms_sent_at' => 'datetime',
            'reminded_at' => 'datetime',
            'completed_at' => 'datetime',
            'notify_in_app' => 'boolean',
            'notify_sms' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
