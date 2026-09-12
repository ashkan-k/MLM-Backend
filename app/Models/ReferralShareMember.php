<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReferralShareMember extends Model
{
    protected $fillable = ['referral_id', 'user_id', 'share_percent', 'approved_at'];

    protected function casts(): array
    {
        return [
            'share_percent' => 'decimal:3',
            'approved_at' => 'datetime',
        ];
    }

    public function referral(): BelongsTo
    {
        return $this->belongsTo(RepresentativeReferral::class, 'referral_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
