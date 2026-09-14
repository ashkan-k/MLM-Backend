<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class CourseLevel extends Model
{
    protected $fillable = [
        'course_id',
        'title',
        'sort_order',
        'passing_score',
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
            'passing_score' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    protected function attachmentUrl(): Attribute
    {
        return Attribute::get(fn () => $this->attachment_path ? Storage::disk('public')->url($this->attachment_path) : null);
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
