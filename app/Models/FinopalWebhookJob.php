<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinopalWebhookJob extends Model
{
    protected $fillable = [
        'uuid',
        'endpoint',
        'queue',
        'status',
        'http_status',
        'success',
        'national_id',
        'mobile',
        'referral_code',
        'idempotency_key',
        'message',
        'request_summary',
        'response_summary',
        'errors',
        'exception',
        'duration_ms',
        'ip_address',
        'user_agent',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'success' => 'boolean',
            'request_summary' => 'array',
            'response_summary' => 'array',
            'errors' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
