<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Course extends Model
{
    protected $fillable = [
        'title',
        'description',
        'is_active',
        'is_required_for_promotion',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_required_for_promotion' => 'boolean',
        ];
    }

    public function levels(): HasMany
    {
        return $this->hasMany(CourseLevel::class)->orderBy('sort_order');
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'course_role_targets');
    }

    public function progress(): HasMany
    {
        return $this->hasMany(UserCourseProgress::class);
    }
}
