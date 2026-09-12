<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'mobile',
        'email',
        'password',
        'avatar',
        'is_active',
    ];

    protected $appends = ['avatar_url'];

    protected $hidden = [
        'password',
        'remember_token',
        'avatar',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    protected function avatarUrl(): Attribute
    {
        return Attribute::get(fn () => $this->avatar ? Storage::disk('public')->url($this->avatar) : null);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles')
            ->withPivot(['effective_from', 'effective_to', 'is_primary', 'is_active'])
            ->withTimestamps();
    }

    public function userRoles(): HasMany
    {
        return $this->hasMany(UserRole::class);
    }

    public function activeRoles()
    {
        return $this->roles()->where('user_roles.is_active', true)->where('roles.is_active', true);
    }

    public function organizationNodes(): HasMany
    {
        return $this->hasMany(OrganizationNode::class);
    }

    public function wallets(): HasMany
    {
        return $this->hasMany(Wallet::class);
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(PersonalAccessToken::class);
    }

    public function hasRole(string $slug): bool
    {
        return $this->activeRoles()->where('slug', $slug)->exists();
    }

    public function isSuperuser(): bool
    {
        return $this->hasRole('superuser');
    }

    public function createApiToken(?int $activeRoleId = null, string $name = 'api'): array
    {
        $plain = Str::random(64);

        $token = $this->tokens()->create([
            'name' => $name,
            'token' => hash('sha256', $plain),
            'active_role_id' => $activeRoleId,
        ]);

        return [$token, $plain];
    }
}
