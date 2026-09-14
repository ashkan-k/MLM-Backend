<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GatewaySale extends Model
{
    protected $fillable = [
        'gateway_id',
        'customer_id',
        'shared_link_id',
        'amount',
        'full_sales_points',
        'status',
        'sold_at',
        'idempotency_key',
        'shaparak_reference',
        'inspected_at',
        'inspected_by',
        'shaparak_at',
        'shaparak_by',
        'rejected_at',
        'rejected_by',
        'rejection_note',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'sold_at' => 'datetime',
            'inspected_at' => 'datetime',
            'shaparak_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function gateway(): BelongsTo
    {
        return $this->belongsTo(Gateway::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function representatives(): HasMany
    {
        return $this->hasMany(GatewayRepresentative::class);
    }

    public function referrers(): HasMany
    {
        return $this->hasMany(GatewayReferrer::class);
    }

    public function managers(): HasMany
    {
        return $this->hasMany(GatewayManager::class);
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(Commission::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(GatewaySaleReview::class)->orderBy('id');
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspected_by');
    }

    public function shaparakReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shaparak_by');
    }
}
