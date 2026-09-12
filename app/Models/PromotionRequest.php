<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PromotionRequest extends Model
{
    protected $fillable = [
        'user_id',
        'from_role_id',
        'target_role_id',
        'status',
        'submitted_at',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function fromRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'from_role_id');
    }

    public function targetRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'target_role_id');
    }

    public function criteria(): HasMany
    {
        return $this->hasMany(PromotionCriteriaResult::class);
    }

    public function feedback(): HasMany
    {
        return $this->hasMany(ManagerFeedback::class);
    }
}
