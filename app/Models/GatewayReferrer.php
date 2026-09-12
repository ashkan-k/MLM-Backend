<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GatewayReferrer extends Model
{
    protected $fillable = ['gateway_sale_id', 'user_id', 'share_percent', 'commission_percent'];

    protected function casts(): array
    {
        return [
            'share_percent' => 'decimal:3',
            'commission_percent' => 'decimal:3',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(GatewaySale::class, 'gateway_sale_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
