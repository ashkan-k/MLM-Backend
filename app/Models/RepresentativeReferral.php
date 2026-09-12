<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RepresentativeReferral extends Model
{
    protected $fillable = [
        'referred_user_id',
        'referrer_user_id',
        'source',
        'referral_code_id',
    ];

    public function referred(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_user_id');
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_user_id');
    }

    public function code(): BelongsTo
    {
        return $this->belongsTo(ReferralCode::class, 'referral_code_id');
    }

    public function shareMembers(): HasMany
    {
        return $this->hasMany(ReferralShareMember::class, 'referral_id');
    }
}
