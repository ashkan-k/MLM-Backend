<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SharedLinkMember extends Model
{
    protected $fillable = [
        'shared_link_id',
        'user_id',
        'share_percent',
        'approved',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'share_percent' => 'decimal:3',
            'approved' => 'boolean',
            'approved_at' => 'datetime',
        ];
    }

    public function link(): BelongsTo
    {
        return $this->belongsTo(SharedLink::class, 'shared_link_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
