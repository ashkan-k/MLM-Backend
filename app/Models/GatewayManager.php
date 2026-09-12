<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GatewayManager extends Model
{
    protected $fillable = ['gateway_sale_id', 'user_id', 'role_id', 'commission_percent'];

    protected function casts(): array
    {
        return ['commission_percent' => 'decimal:3'];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(GatewaySale::class, 'gateway_sale_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
