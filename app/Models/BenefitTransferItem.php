<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BenefitTransferItem extends Model
{
    protected $fillable = [
        'benefit_transfer_id',
        'gateway_representative_id',
        'share_percent',
    ];

    protected function casts(): array
    {
        return ['share_percent' => 'decimal:3'];
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(BenefitTransfer::class, 'benefit_transfer_id');
    }
}
