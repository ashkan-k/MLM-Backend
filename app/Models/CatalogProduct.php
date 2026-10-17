<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class CatalogProduct extends Model
{
    protected $fillable = [
        'type',
        'label',
        'requires_merchant',
        'requires_owner',
        'sale_points',
        'default_code',
        'signup_url',
        'aliases',
        'is_system',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'requires_merchant' => 'boolean',
            'requires_owner' => 'boolean',
            'is_system' => 'boolean',
            'is_active' => 'boolean',
            'sale_points' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        $forget = static function () {
            Cache::forget('catalog_products.v1');
        };
        static::saved($forget);
        static::deleted($forget);
    }
}
