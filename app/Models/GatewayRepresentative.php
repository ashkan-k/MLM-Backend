<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GatewayRepresentative extends Model
{
    protected $fillable = ['gateway_sale_id', 'user_id', 'share_percent', 'sales_points'];

    protected function casts(): array
    {
        return [
            'share_percent' => 'decimal:3',
            'sales_points' => 'decimal:3',
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
