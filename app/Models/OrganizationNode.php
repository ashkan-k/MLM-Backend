<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrganizationNode extends Model
{
    protected $fillable = [
        'user_id',
        'parent_node_id',
        'role_id',
        'path',
        'effective_from',
        'effective_to',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
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

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_node_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_node_id');
    }
}
