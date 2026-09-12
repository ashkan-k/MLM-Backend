<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Gateway extends Model
{
    protected $fillable = [
        'external_id',
        'name',
        'source',
        'sale_amount',
        'is_active',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'sale_amount' => 'decimal:2',
            'is_active' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function sales(): HasMany
    {
        return $this->hasMany(GatewaySale::class);
    }
}
