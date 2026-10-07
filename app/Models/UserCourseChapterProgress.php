<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserCourseChapterProgress extends Model
{
    protected $table = 'user_course_chapter_progress';

    protected $fillable = [
        'user_id',
        'course_id',
        'course_level_id',
        'course_level_chapter_id',
        'status',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(CourseLevel::class, 'course_level_id');
    }

    public function chapter(): BelongsTo
    {
        return $this->belongsTo(CourseLevelChapter::class, 'course_level_chapter_id');
    }
}
