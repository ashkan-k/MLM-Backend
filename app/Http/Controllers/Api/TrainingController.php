<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseLevel;
use App\Models\CourseLevelChapter;
use App\Models\User;
use App\Models\UserCourseChapterProgress;
use App\Models\UserCourseProgress;
use App\Services\Organization\OrganizationTreeService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TrainingController extends Controller
{
    public function index(Request $request)
    {
        $courses = $this->coursesFor($request)->values();
        $userId = $request->user()->id;

        $levelProgress = UserCourseProgress::query()
            ->where('user_id', $userId)
            ->get()
            ->keyBy('course_level_id');
        $chapterProgress = UserCourseChapterProgress::query()
            ->where('user_id', $userId)
            ->get()
            ->keyBy('course_level_chapter_id');

        $prevCourseDone = true;

        return response()->json($courses->map(function (Course $course) use ($levelProgress, $chapterProgress, &$prevCourseDone) {
            $courseLocked = ! $prevCourseDone;
            $prevLevelDone = true;

            $levels = $course->levels->map(function (CourseLevel $level) use ($levelProgress, $chapterProgress, $courseLocked, &$prevLevelDone) {
                $levelLocked = $courseLocked || ! $prevLevelDone;
                $row = $levelProgress->get($level->id);
                $chapters = $level->chapters->where('is_active', true)->values();

                $prevChapterDone = true;
                $mappedChapters = $chapters->map(function (CourseLevelChapter $chapter) use ($levelLocked, $chapterProgress, &$prevChapterDone) {
                    $chapterLocked = $levelLocked || ! $prevChapterDone;
                    $cRow = $chapterProgress->get($chapter->id);
                    $completed = ($cRow?->status === 'completed');
                    $prevChapterDone = $completed;

                    return [
                        'id' => $chapter->id,
                        'title' => $chapter->title,
                        'sort_order' => $chapter->sort_order,
                        'content_type' => $chapter->content_type ?? 'text',
                        'content_body' => $chapter->content_body,
                        'content_url' => $chapter->content_url,
                        'attachment_name' => $chapter->attachment_name,
                        'attachment_url' => $chapter->attachment_url,
                        'locked' => $chapterLocked,
                        'progress' => $cRow ? [
                            'status' => $cRow->status,
                            'completed_at' => $cRow->completed_at,
                        ] : null,
                    ];
                })->values();

                $allChaptersDone = $mappedChapters->isNotEmpty()
                    && $mappedChapters->every(fn ($ch) => ($ch['progress']['status'] ?? null) === 'completed');
                // Empty chapters: fall back to level progress / content on level itself.
                if ($mappedChapters->isEmpty()) {
                    $legacyCompleted = ($row?->status === 'completed');
                    $mappedChapters = collect([[
                        'id' => null,
                        'title' => 'فصل ۱',
                        'sort_order' => 1,
                        'content_type' => $level->content_type ?? 'text',
                        'content_body' => $level->content_body,
                        'content_url' => $level->content_url,
                        'attachment_name' => $level->attachment_name,
                        'attachment_url' => $level->attachment_url,
                        'locked' => $levelLocked,
                        'progress' => $legacyCompleted ? [
                            'status' => 'completed',
                            'completed_at' => $row?->completed_at,
                        ] : null,
                    ]]);
                    $allChaptersDone = $legacyCompleted;
                }

                $levelCompleted = ($row?->status === 'completed') || $allChaptersDone;
                $prevLevelDone = $levelCompleted;

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
                    'locked' => $levelLocked,
                    'chapters' => $mappedChapters,
                    'progress' => $levelCompleted ? [
                        'status' => 'completed',
                        'score' => $row?->score,
                        'progress_percent' => $row?->progress_percent ?? 100,
                        'completed_at' => $row?->completed_at,
                    ] : ($row ? [
                        'status' => $row->status,
                        'score' => $row->score,
                        'progress_percent' => $row->progress_percent,
                        'completed_at' => $row->completed_at,
                    ] : null),
                ];
            })->values();

            $courseCompleted = $levels->isNotEmpty()
                && $levels->every(fn ($l) => ($l['progress']['status'] ?? null) === 'completed');
            $prevCourseDone = $courseCompleted;

            return [
                'id' => $course->id,
                'title' => $course->title,
                'description' => $course->description,
                'is_required_for_promotion' => $course->is_required_for_promotion,
                'locked' => $courseLocked,
                'roles' => $course->roles->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'slug' => $r->slug]),
                'levels' => $levels,
            ];
        })->values());
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

        $courses = Course::query()->with(['levels.chapters'])->where('is_active', true)->orderBy('id')->get();
        $progress = UserCourseProgress::query()
            ->whereIn('user_id', $ids)
            ->get()
            ->groupBy('user_id');
        $chapterProgress = UserCourseChapterProgress::query()
            ->whereIn('user_id', $ids)
            ->get()
            ->groupBy('user_id');

        $users = User::query()->whereIn('id', $ids)->orderBy('name')->get();

        return response()->json($users->map(function (User $user) use ($courses, $progress, $chapterProgress) {
            $rows = $progress->get($user->id, collect());
            $cRows = $chapterProgress->get($user->id, collect());

            return [
                'id' => $user->id,
                'name' => $user->name,
                'mobile' => $user->mobile,
                'courses' => $courses->map(function (Course $course) use ($rows, $cRows) {
                    $levels = $course->levels;
                    $done = $levels->filter(fn ($level) => $this->levelCompletedFromRows($level, $rows, $cRows))->count();

                    return [
                        'id' => $course->id,
                        'title' => $course->title,
                        'done' => $done,
                        'total' => $levels->count(),
                        'levels' => $levels->map(function ($level) use ($rows, $cRows) {
                            $row = $rows->firstWhere('course_level_id', $level->id);
                            $chapters = $level->chapters;
                            $chaptersDone = $chapters->filter(fn ($ch) => optional($cRows->firstWhere('course_level_chapter_id', $ch->id))->status === 'completed')->count();
                            $status = $row?->status;
                            if (! $status && $chapters->isNotEmpty() && $chaptersDone === $chapters->count()) {
                                $status = 'completed';
                            }

                            return [
                                'id' => $level->id,
                                'title' => $level->title,
                                'passing_score' => $level->passing_score,
                                'status' => $status,
                                'score' => $row?->score,
                                'chapters_done' => $chaptersDone,
                                'chapters_total' => $chapters->count(),
                            ];
                        })->values(),
                    ];
                })->values(),
            ];
        })->values());
    }

    public function completeChapter(Request $request, Course $course, CourseLevel $level, CourseLevelChapter $chapter)
    {
        if ((int) $level->course_id !== (int) $course->id || (int) $chapter->course_level_id !== (int) $level->id) {
            abort(404);
        }

        $this->assertChapterUnlocked($request, $course, $level, $chapter);

        UserCourseChapterProgress::query()->updateOrCreate(
            [
                'user_id' => $request->user()->id,
                'course_level_chapter_id' => $chapter->id,
            ],
            [
                'course_id' => $course->id,
                'course_level_id' => $level->id,
                'status' => 'completed',
                'completed_at' => now(),
            ]
        );

        $allDone = $level->chapters()->where('is_active', true)->get()->every(function (CourseLevelChapter $ch) use ($request) {
            return UserCourseChapterProgress::query()
                ->where('user_id', $request->user()->id)
                ->where('course_level_chapter_id', $ch->id)
                ->where('status', 'completed')
                ->exists();
        });

        $progress = null;
        if ($allDone) {
            $progress = UserCourseProgress::query()->updateOrCreate(
                [
                    'user_id' => $request->user()->id,
                    'course_id' => $course->id,
                    'course_level_id' => $level->id,
                ],
                [
                    'status' => 'completed',
                    'score' => 100,
                    'progress_percent' => 100,
                    'completed_at' => now(),
                ]
            );
        }

        return response()->json([
            'ok' => true,
            'chapter_completed' => true,
            'level_completed' => $allDone,
            'level_progress' => $progress,
        ]);
    }

    public function submit(Request $request, Course $course, CourseLevel $level)
    {
        if ((int) $level->course_id !== (int) $course->id) {
            abort(404);
        }

        $this->assertLevelUnlocked($request, $course, $level);

        $activeChapters = $level->chapters()->where('is_active', true)->get();
        if ($activeChapters->isNotEmpty()) {
            $allChaptersDone = $activeChapters->every(function (CourseLevelChapter $ch) use ($request) {
                return UserCourseChapterProgress::query()
                    ->where('user_id', $request->user()->id)
                    ->where('course_level_chapter_id', $ch->id)
                    ->where('status', 'completed')
                    ->exists();
            });
            if (! $allChaptersDone) {
                throw new HttpException(422, 'ابتدا همهٔ فصل‌های این سطح را تکمیل کنید.');
            }
        }

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

    private function coursesFor(Request $request): Collection
    {
        $role = $request->attributes->get('active_role');
        $query = Course::query()
            ->with(['levels.chapters', 'roles'])
            ->where('is_active', true)
            ->orderBy('id');

        if ($role && ! $request->user()->isSuperuser()) {
            $query->whereHas('roles', fn ($q) => $q->where('roles.id', $role->id));
        }

        return $query->get();
    }

    private function assertCourseUnlocked(Request $request, Course $course): void
    {
        $ordered = $this->coursesFor($request);
        foreach ($ordered as $row) {
            if ((int) $row->id === (int) $course->id) {
                return;
            }
            if (! $this->isCourseCompleted($request->user()->id, $row)) {
                throw new HttpException(422, 'تا تکمیل دورهٔ قبلی نمی‌توانید این دوره را باز کنید.');
            }
        }
    }

    private function assertLevelUnlocked(Request $request, Course $course, CourseLevel $level): void
    {
        $this->assertCourseUnlocked($request, $course);

        foreach ($course->levels()->orderBy('sort_order')->orderBy('id')->get() as $row) {
            if ((int) $row->id === (int) $level->id) {
                return;
            }
            if (! $this->isLevelCompleted($request->user()->id, $course->id, $row)) {
                throw new HttpException(422, 'تا تکمیل سطح قبلی نمی‌توانید این سطح را باز کنید.');
            }
        }
    }

    private function assertChapterUnlocked(Request $request, Course $course, CourseLevel $level, CourseLevelChapter $chapter): void
    {
        $this->assertLevelUnlocked($request, $course, $level);

        foreach ($level->chapters()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get() as $row) {
            if ((int) $row->id === (int) $chapter->id) {
                return;
            }
            $done = UserCourseChapterProgress::query()
                ->where('user_id', $request->user()->id)
                ->where('course_level_chapter_id', $row->id)
                ->where('status', 'completed')
                ->exists();
            if (! $done) {
                throw new HttpException(422, 'تا تکمیل فصل قبلی نمی‌توانید این فصل را باز کنید.');
            }
        }
    }

    private function isCourseCompleted(int $userId, Course $course): bool
    {
        $course->loadMissing('levels.chapters');
        if ($course->levels->isEmpty()) {
            return true;
        }

        return $course->levels->every(fn (CourseLevel $level) => $this->isLevelCompleted($userId, $course->id, $level));
    }

    private function isLevelCompleted(int $userId, int $courseId, CourseLevel $level): bool
    {
        $levelDone = UserCourseProgress::query()
            ->where('user_id', $userId)
            ->where('course_id', $courseId)
            ->where('course_level_id', $level->id)
            ->where('status', 'completed')
            ->exists();
        if ($levelDone) {
            return true;
        }

        $level->loadMissing('chapters');
        $chapters = $level->chapters;
        if ($chapters->isEmpty()) {
            return false;
        }

        return $chapters->every(function (CourseLevelChapter $ch) use ($userId) {
            return UserCourseChapterProgress::query()
                ->where('user_id', $userId)
                ->where('course_level_chapter_id', $ch->id)
                ->where('status', 'completed')
                ->exists();
        });
    }

    private function levelCompletedFromRows(CourseLevel $level, Collection $rows, Collection $cRows): bool
    {
        if (optional($rows->firstWhere('course_level_id', $level->id))->status === 'completed') {
            return true;
        }
        $chapters = $level->chapters;
        if ($chapters->isEmpty()) {
            return false;
        }

        return $chapters->every(fn ($ch) => optional($cRows->firstWhere('course_level_chapter_id', $ch->id))->status === 'completed');
    }
}
