<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Wallet extends Model
{
    protected $fillable = [
        'user_id',
        'role_id',
        'currency',
        'balance',
        'held_balance',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'balance' => 'decimal:3',
            'held_balance' => 'decimal:3',
            'is_active' => 'boolean',
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

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    public function availableBalance(): string
    {
        return bcsub((string) $this->balance, (string) $this->held_balance, 3);
    }
}
