<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseLevel;
use App\Models\User;
use App\Models\UserCourseProgress;
use App\Services\Organization\OrganizationTreeService;
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
                        'content_type' => $level->content_type ?? 'text',
                        'content_body' => $level->content_body,
                        'content_url' => $level->content_url,
                        'attachment_name' => $level->attachment_name,
                        'attachment_url' => $level->attachment_url,
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

    public function teamProgress(Request $request, OrganizationTreeService $tree)
    {
        $ids = $tree->descendants($request->user())->pluck('id');
        if ($request->user()->isSuperuser()) {
            $ids = User::query()->where('is_active', true)->pluck('id');
        }

        $courses = Course::query()->with('levels')->where('is_active', true)->get();
        $progress = UserCourseProgress::query()
            ->whereIn('user_id', $ids)
            ->get()
            ->groupBy('user_id');

        $users = User::query()->whereIn('id', $ids)->orderBy('name')->get();

        return response()->json($users->map(function (User $user) use ($courses, $progress) {
            $rows = $progress->get($user->id, collect());
            return [
                'id' => $user->id,
                'name' => $user->name,
                'mobile' => $user->mobile,
                'courses' => $courses->map(function (Course $course) use ($rows) {
                    $levels = $course->levels;
                    $done = $levels->filter(fn ($level) => optional($rows->firstWhere('course_level_id', $level->id))->status === 'completed')->count();
                    return [
                        'id' => $course->id,
                        'title' => $course->title,
                        'done' => $done,
                        'total' => $levels->count(),
                        'levels' => $levels->map(function ($level) use ($rows) {
                            $row = $rows->firstWhere('course_level_id', $level->id);
                            return [
                                'id' => $level->id,
                                'title' => $level->title,
                                'passing_score' => $level->passing_score,
                                'status' => $row?->status,
                                'score' => $row?->score,
                            ];
                        })->values(),
                    ];
                })->values(),
            ];
        })->values());
    }

    public function submit(Request $request, Course $course, CourseLevel $level)
    {
        $data = $request->validate([
            'score' => ['nullable', 'numeric', 'min:0'],
            'progress_percent' => ['nullable', 'numeric'],
        ]);

        $hasExam = array_key_exists('score', $data) && $data['score'] !== null;
        $passed = $hasExam
            ? (float) $data['score'] >= (float) $level->passing_score
            : true;
        $progress = UserCourseProgress::query()->updateOrCreate(
            [
                'user_id' => $request->user()->id,
                'course_id' => $course->id,
                'course_level_id' => $level->id,
            ],
            [
                'status' => $passed ? 'completed' : 'failed',
                'score' => $hasExam ? $data['score'] : 0,
                'progress_percent' => $data['progress_percent'] ?? ($passed ? 100 : 50),
                'completed_at' => $passed ? now() : null,
            ]
        );

        return response()->json($progress);
    }
}
