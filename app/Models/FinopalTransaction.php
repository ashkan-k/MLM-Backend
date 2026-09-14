<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinopalTransaction extends Model
{
    protected $fillable = [
        'gateway_id',
        'gateway_sale_id',
        'merchant_code',
        'event',
        'authority',
        'ref_id',
        'order_id',
        'amount',
        'profit',
        'currency',
        'status',
        'code',
        'paid_at',
        'idempotency_key',
        'payload',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:3',
            'profit' => 'decimal:3',
            'paid_at' => 'datetime',
            'processed_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    public function gateway(): BelongsTo
    {
        return $this->belongsTo(Gateway::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(GatewaySale::class, 'gateway_sale_id');
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(Commission::class);
    }
}
