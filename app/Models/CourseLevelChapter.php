<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class CourseLevelChapter extends Model
{
    protected $fillable = [
        'course_level_id',
        'title',
        'sort_order',
        'content_type',
        'content_body',
        'content_url',
        'attachment_path',
        'attachment_name',
        'is_active',
    ];

    protected $appends = ['attachment_url'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected function attachmentUrl(): Attribute
    {
        return Attribute::get(fn () => $this->attachment_path ? Storage::disk('public')->url($this->attachment_path) : null);
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(CourseLevel::class, 'course_level_id');
    }

    public function progress(): HasMany
    {
        return $this->hasMany(UserCourseChapterProgress::class);
    }
}
