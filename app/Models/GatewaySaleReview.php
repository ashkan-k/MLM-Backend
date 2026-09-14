<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GatewaySaleReview extends Model
{
    protected $fillable = [
        'gateway_sale_id',
        'actor_id',
        'stage',
        'decision',
        'note',
        'reference',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(GatewaySale::class, 'gateway_sale_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
