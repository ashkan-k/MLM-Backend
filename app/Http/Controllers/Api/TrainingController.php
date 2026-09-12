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

        return response()->json($query->get());
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
