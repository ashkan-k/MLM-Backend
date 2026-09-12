<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    protected $fillable = ['name', 'mobile', 'metadata'];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function sales(): HasMany
    {
        return $this->hasMany(GatewaySale::class);
    }
}
