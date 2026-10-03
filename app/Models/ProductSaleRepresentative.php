<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductSaleRepresentative extends Model
{
    protected $fillable = [
        'product_sale_id',
        'user_id',
        'share_percent',
        'sales_points',
    ];

    protected function casts(): array
    {
        return [
            'share_percent' => 'decimal:3',
            'sales_points' => 'decimal:3',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(ProductSale::class, 'product_sale_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
