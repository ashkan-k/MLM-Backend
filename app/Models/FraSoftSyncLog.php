<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FraSoftSyncLog extends Model
{
    protected $table = 'frasoft_sync_logs';

    protected $fillable = [
        'direction',
        'event_type',
        'idempotency_key',
        'status',
        'attempts',
        'payload',
        'error',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}
