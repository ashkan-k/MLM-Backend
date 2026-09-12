<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WithdrawalApproval extends Model
{
    protected $fillable = [
        'withdrawal_request_id',
        'approver_user_id',
        'stage',
        'decision',
        'note',
        'decided_at',
    ];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(WithdrawalRequest::class, 'withdrawal_request_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }
}
