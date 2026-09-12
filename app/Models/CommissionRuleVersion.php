<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommissionRuleVersion extends Model
{
    protected $fillable = [
        'commission_rule_id',
        'version',
        'percent',
        'qualified_percent',
        'conditions',
        'effective_from',
        'effective_to',
    ];

    protected function casts(): array
    {
        return [
            'percent' => 'decimal:3',
            'qualified_percent' => 'decimal:3',
            'conditions' => 'array',
            'effective_from' => 'datetime',
            'effective_to' => 'datetime',
        ];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(CommissionRule::class, 'commission_rule_id');
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(Commission::class, 'rule_version_id');
    }
}
