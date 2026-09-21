<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPointAdjustment extends Model
{
    protected $fillable = [
        'user_id',
        'role_id',
        'delta_points',
        'month_key',
        'note',
        'actor_user_id',
    ];

    protected function casts(): array
    {
        return [
            'delta_points' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
