<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserGoalBankAccess extends Model
{
    protected $table = 'user_goal_bank_access';

    protected $fillable = [
        'user_id',
        'role_id',
        'enabled',
        'max_concurrent',
        'granted_by',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'max_concurrent' => 'integer',
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

    public function granter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}
