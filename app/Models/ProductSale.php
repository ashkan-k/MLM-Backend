<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductSale extends Model
{
    protected $fillable = [
        'product_type',
        'product_code',
        'title',
        'external_ref',
        'amount',
        'full_sales_points',
        'status',
        'sold_at',
        'idempotency_key',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:3',
            'full_sales_points' => 'integer',
            'sold_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function representatives(): HasMany
    {
        return $this->hasMany(ProductSaleRepresentative::class);
    }

    public function referrers(): HasMany
    {
        return $this->hasMany(ProductSaleReferrer::class);
    }

    public function managers(): HasMany
    {
        return $this->hasMany(ProductSaleManager::class);
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(Commission::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(FinopalTransaction::class);
    }
}
