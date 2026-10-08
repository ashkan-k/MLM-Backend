<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Commission;
use App\Models\Course;
use App\Models\GatewayRepresentative;
use App\Models\PromotionRequest;
use App\Models\RepresentativeReferral;
use App\Models\UserCourseProgress;
use App\Models\Wallet;
use App\Services\Promotion\PromotionService;
use Illuminate\Http\Request;

class PromotionController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $query = PromotionRequest::query()->with(['user', 'fromRole', 'targetRole', 'criteria', 'feedback']);

        if (! $user->isSuperuser() && ! $user->hasRole('senior_manager')) {
            $query->where('user_id', $user->id);
        }

        return response()->json($query->latest()->get());
    }

    public function show(Request $request, PromotionRequest $promotion, PromotionService $service)
    {
        $actor = $request->user();
        if (! $actor->isSuperuser() && ! $actor->hasRole('senior_manager') && $promotion->user_id !== $actor->id) {
            abort(403);
        }

        $promotion->load(['user.roles', 'fromRole', 'targetRole', 'criteria', 'feedback.reviewer']);
        $user = $promotion->user;
        $criteria = $this->presentCriteria($promotion, $service);
        $courses = Course::query()->with('levels')->where('is_active', true)->get();
        $progress = UserCourseProgress::query()->where('user_id', $user->id)->get();

        return response()->json([
            'request' => $promotion,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'mobile' => $user->mobile,
                'email' => $user->email,
                'is_active' => $user->is_active,
                'roles' => $user->roles->map(fn ($r) => ['name' => $r->name, 'slug' => $r->slug]),
            ],
            'criteria' => $criteria,
            'training' => $courses->map(function (Course $course) use ($progress) {
                $levels = $course->levels->map(function ($level) use ($progress) {
                    $row = $progress->firstWhere('course_level_id', $level->id);
                    return [
                        'id' => $level->id,
                        'title' => $level->title,
                        'status' => $row?->status,
                        'completed_at' => $row?->completed_at,
                    ];
                });
                return [
                    'id' => $course->id,
                    'title' => $course->title,
                    'done' => $levels->where('status', 'completed')->count(),
                    'total' => $levels->count(),
                    'levels' => $levels->values(),
                ];
            })->values(),
            'sales' => [
                'count' => GatewayRepresentative::query()->where('user_id', $user->id)->count(),
                'points' => (float) GatewayRepresentative::query()->where('user_id', $user->id)->sum('sales_points'),
            ],
            'referrals' => RepresentativeReferral::query()
                ->with('referred:id,name,mobile')
                ->where('referrer_user_id', $user->id)
                ->latest()
                ->limit(20)
                ->get(),
            'commissions' => Commission::query()
                ->with('role:id,name')
                ->where('user_id', $user->id)
                ->latest()
                ->limit(15)
                ->get(['id', 'role_id', 'commission_amount', 'status', 'created_at']),
            'wallets' => Wallet::query()->with('role:id,name')->where('user_id', $user->id)->get(['id', 'role_id', 'balance', 'held_balance']),
            'activities' => AuditLog::query()
                ->with('actor:id,name')
                ->where(function ($q) use ($user, $promotion) {
                    $q->where('actor_user_id', $user->id)
                        ->orWhere(function ($inner) use ($user, $promotion) {
                            $inner->where('auditable_type', PromotionRequest::class)->where('auditable_id', $promotion->id);
                        });
                })
                ->latest('created_at')
                ->limit(20)
                ->get(),
        ]);
    }

    public function eligibility(Request $request, PromotionService $service)
    {
        $target = $request->query('target', 'sales_manager');

        return response()->json(
            collect($service->evaluate($request->user(), $target))
                ->reject(fn ($row) => ($row['code'] ?? '') === 'team_satisfaction')
                ->values()
        );
    }

    public function store(Request $request, PromotionService $service)
    {
        $data = $request->validate([
            'from_role' => ['required', 'string'],
            'target_role' => ['required', 'string'],
        ]);

        return response()->json($service->request($request->user(), $data['from_role'], $data['target_role']), 201);
    }

    public function decide(Request $request, PromotionRequest $promotion, PromotionService $service)
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'note' => ['required_if:decision,rejected', 'nullable', 'string'],
        ]);

        return response()->json($service->decide($request->user(), $promotion, $data['decision'], $data['note'] ?? ''));
    }

    /**
     * Pending dossiers show live numbers (header sales points and criteria stay in sync).
     * Decided requests keep the snapshot taken at submission.
     * Interview / team-satisfaction are yes-no, not a 0-of-1 score.
     */
    private function presentCriteria(PromotionRequest $promotion, PromotionService $service)
    {
        $targetSlug = $promotion->targetRole?->slug ?? 'sales_manager';
        $live = $promotion->status === 'pending'
            ? collect($service->evaluate($promotion->user, $targetSlug))->keyBy('code')
            : collect();

        return $promotion->criteria
            ->reject(fn ($row) => $row->criterion_code === 'team_satisfaction')
            ->map(function ($row) use ($live, $promotion) {
            $code = (string) $row->criterion_code;
            $boolean = in_array($code, ['senior_assessment', 'team_satisfaction'], true);

            if ($live->has($code) && ! $boolean) {
                $fresh = $live->get($code);
                $row->actual_value = $fresh['actual'];
                $row->required_value = $fresh['required'];
                $row->passed = (bool) $fresh['passed'];
            }

            if ($code === 'senior_assessment') {
                $row->passed = $promotion->status === 'approved';
                $row->actual_value = $row->passed ? 1 : 0;
            }

            $row->setAttribute('kind', $boolean ? 'boolean' : 'score');

            return $row;
        })->values();
    }
}
