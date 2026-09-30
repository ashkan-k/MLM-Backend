<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Wallet extends Model
{
    /** پورسانت و پاداش سهم خود همان نقش. */
    public const KIND_ROLE = 'role';

    /** مابقی پاداش ماهانه که به مدیر ارشد می‌رسد و با سهم شخصی قاطی نمی‌شود. */
    public const KIND_BONUS_RESIDUAL = 'bonus_residual';

    protected $fillable = [
        'user_id',
        'role_id',
        'kind',
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
