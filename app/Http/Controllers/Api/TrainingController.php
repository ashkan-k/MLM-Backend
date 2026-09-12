<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseLevel;
use App\Models\UserCourseProgress;
use Illuminate\Http\Request;

class TrainingController extends Controller
{
    public function index(Request $request)
    {
        $role = $request->attributes->get('active_role');
        $query = Course::query()->with(['levels', 'roles'])->where('is_active', true);
        if ($role && ! $request->user()->isSuperuser()) {
            $query->whereHas('roles', fn ($q) => $q->where('roles.id', $role->id));
        }

        $courses = $query->get();
        $progress = UserCourseProgress::query()
            ->where('user_id', $request->user()->id)
            ->get()
            ->keyBy('course_level_id');

        return response()->json($courses->map(function (Course $course) use ($progress) {
            return [
                'id' => $course->id,
                'title' => $course->title,
                'description' => $course->description,
                'is_required_for_promotion' => $course->is_required_for_promotion,
                'roles' => $course->roles->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'slug' => $r->slug]),
                'levels' => $course->levels->map(function ($level) use ($progress) {
                    $row = $progress->get($level->id);

                    return [
                        'id' => $level->id,
                        'title' => $level->title,
                        'sort_order' => $level->sort_order,
                        'passing_score' => $level->passing_score,
                        'progress' => $row ? [
                            'status' => $row->status,
                            'score' => $row->score,
                            'progress_percent' => $row->progress_percent,
                            'completed_at' => $row->completed_at,
                        ] : null,
                    ];
                }),
            ];
        }));
    }

    public function progress(Request $request)
    {
        return response()->json(
            UserCourseProgress::query()
                ->with(['course', 'level'])
                ->where('user_id', $request->user()->id)
                ->get()
        );
    }

    public function submit(Request $request, Course $course, CourseLevel $level)
    {
        $data = $request->validate([
            'score' => ['required', 'numeric', 'min:0'],
            'progress_percent' => ['nullable', 'numeric'],
        ]);

        $passed = (float) $data['score'] >= (float) $level->passing_score;
        $progress = UserCourseProgress::query()->updateOrCreate(
            [
                'user_id' => $request->user()->id,
                'course_id' => $course->id,
                'course_level_id' => $level->id,
            ],
            [
                'status' => $passed ? 'completed' : 'failed',
                'score' => $data['score'],
                'progress_percent' => $data['progress_percent'] ?? ($passed ? 100 : 50),
                'completed_at' => $passed ? now() : null,
            ]
        );

        return response()->json($progress);
    }
}
