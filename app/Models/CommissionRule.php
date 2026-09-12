<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommissionRule extends Model
{
    protected $fillable = [
        'code',
        'name',
        'default_percent',
        'qualification_type',
        'conditions',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'default_percent' => 'decimal:3',
            'conditions' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(CommissionRuleVersion::class);
    }
}
