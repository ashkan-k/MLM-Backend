<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoalBankItem extends Model
{
    public const STATUS_AVAILABLE = 'available';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'name',
        'platform',
        'platform_url',
        'audience_count',
        'description',
        'province',
        'city',
        'address',
        'phone',
        'mobile',
        'status',
        'claimed_by_user_id',
        'claimed_by_role_id',
        'claimed_at',
        'work_report',
        'failure_reason',
        'disposed_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'audience_count' => 'integer',
            'claimed_at' => 'datetime',
            'disposed_at' => 'datetime',
        ];
    }

    public function claimant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimed_by_user_id');
    }

    public function claimRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'claimed_by_role_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isAvailable(): bool
    {
        return $this->status === self::STATUS_AVAILABLE;
    }

    public function isInProgress(): bool
    {
        return $this->status === self::STATUS_IN_PROGRESS;
    }
}
