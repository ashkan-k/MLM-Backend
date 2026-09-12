<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Commission extends Model
{
    protected $fillable = [
        'user_id',
        'role_id',
        'gateway_sale_id',
        'rule_version_id',
        'base_amount',
        'commission_percent',
        'commission_amount',
        'status',
        'idempotency_key',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'base_amount' => 'decimal:2',
            'commission_percent' => 'decimal:3',
            'commission_amount' => 'decimal:3',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(GatewaySale::class, 'gateway_sale_id');
    }

    public function ruleVersion(): BelongsTo
    {
        return $this->belongsTo(CommissionRuleVersion::class, 'rule_version_id');
    }
}
