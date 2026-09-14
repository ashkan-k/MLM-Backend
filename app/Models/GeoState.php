<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GeoState extends Model
{
    public $incrementing = false;

    protected $fillable = ['id', 'code', 'title', 'slug'];

    public function cities(): HasMany
    {
        return $this->hasMany(GeoCity::class, 'state_id');
    }
}
