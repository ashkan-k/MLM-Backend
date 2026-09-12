<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromotionCriteriaResult extends Model
{
    protected $fillable = [
        'promotion_request_id',
        'criterion_code',
        'required_value',
        'actual_value',
        'passed',
        'evidence',
    ];

    protected function casts(): array
    {
        return [
            'required_value' => 'decimal:3',
            'actual_value' => 'decimal:3',
            'passed' => 'boolean',
            'evidence' => 'array',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(PromotionRequest::class, 'promotion_request_id');
    }
}
