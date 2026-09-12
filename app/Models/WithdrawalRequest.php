<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WithdrawalRequest extends Model
{
    public const REQUESTED = 'requested';
    public const SENIOR_MANAGER_PENDING = 'senior_manager_pending';
    public const SUPERUSER_PENDING = 'superuser_pending';
    public const PROCESSING = 'processing';
    public const COMPLETED = 'completed';
    public const REJECTED = 'rejected';
    public const CANCELLED = 'cancelled';
    public const FAILED = 'failed';

    protected $fillable = [
        'wallet_id',
        'user_id',
        'amount',
        'status',
        'idempotency_key',
        'requested_at',
        'completed_at',
        'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:3',
            'requested_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(WithdrawalApproval::class);
    }
}
