<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'hierarchy_level',
        'is_organizational',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_organizational' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_roles')
            ->withPivot(['effective_from', 'effective_to', 'is_primary', 'is_active']);
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions')
            ->withPivot('allowed');
    }

    public function wallets(): HasMany
    {
        return $this->hasMany(Wallet::class);
    }
}
