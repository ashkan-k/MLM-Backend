<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GeoCity extends Model
{
    public $incrementing = false;

    protected $fillable = ['id', 'state_id', 'code', 'slug', 'title', 'sub_title'];

    public function state(): BelongsTo
    {
        return $this->belongsTo(GeoState::class, 'state_id');
    }
}
