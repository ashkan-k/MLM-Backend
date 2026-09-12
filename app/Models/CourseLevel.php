<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourseLevel extends Model
{
    protected $fillable = ['course_id', 'title', 'sort_order', 'passing_score', 'is_active'];

    protected function casts(): array
    {
        return [
            'passing_score' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function progress(): HasMany
    {
        return $this->hasMany(UserCourseProgress::class);
    }
}
