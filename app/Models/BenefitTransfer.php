<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BenefitTransfer extends Model
{
    protected $fillable = [
        'from_user_id',
        'to_user_id',
        'gateway_sale_id',
        'transfer_type',
        'status',
        'effective_from',
        'reason',
    ];

    protected function casts(): array
    {
        return ['effective_from' => 'datetime'];
    }

    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    public function toUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(BenefitTransferItem::class);
    }
}
