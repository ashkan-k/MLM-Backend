<?php

namespace App\Services\Promotion;

use App\Models\Course;
use App\Models\GatewayRepresentative;
use App\Models\Notification;
use App\Models\PromotionCriteriaResult;
use App\Models\PromotionRequest;
use App\Models\RepresentativeReferral;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\UserCourseProgress;
use App\Models\UserRole;
use App\Services\Audit\AuditService;
use App\Services\Organization\OrganizationTreeService;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\DB;

class PromotionService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly OrganizationTreeService $tree,
        private readonly WalletService $wallets,
    ) {}

    public function evaluate(User $user, string $targetSlug): array
    {
        $settings = SystemSetting::getValue('promotion_criteria', $this->defaults());

        if ($targetSlug === 'sales_manager') {
            $points = (float) GatewayRepresentative::query()->where('user_id', $user->id)->sum('sales_points');
            $referred = RepresentativeReferral::query()->where('referrer_user_id', $user->id)->pluck('referred_user_id');
            $strong = GatewayRepresentative::query()
                ->selectRaw('user_id, sum(sales_points) as pts')
                ->whereIn('user_id', $referred)
                ->groupBy('user_id')
                ->havingRaw('sum(sales_points) >= ?', [$settings['sm_rep_points']])
                ->count();

            return [
                ['code' => 'personal_points', 'required' => $settings['sm_personal_points'], 'actual' => $points, 'passed' => $points >= $settings['sm_personal_points']],
                ['code' => 'new_representatives', 'required' => $settings['sm_new_reps'], 'actual' => $referred->count(), 'passed' => $referred->count() >= $settings['sm_new_reps']],
                ['code' => 'strong_representatives', 'required' => $settings['sm_strong_reps'], 'actual' => $strong, 'passed' => $strong >= $settings['sm_strong_reps']],
                $this->trainingCriterion($user, 'representative'),
                ['code' => 'senior_assessment', 'required' => 1, 'actual' => 0, 'passed' => false],
            ];
        }

        $from = $user->userRoles()->whereHas('role', fn ($q) => $q->where('slug', 'sales_manager'))->orderBy('effective_from')->first();
        $years = $from ? $from->effective_from->diffInDays(now()) / 365 : 0;
        $referred = RepresentativeReferral::query()->where('referrer_user_id', $user->id)->pluck('referred_user_id');
        $strong = GatewayRepresentative::query()
            ->selectRaw('user_id, sum(sales_points) as pts')
            ->whereIn('user_id', $referred)
            ->groupBy('user_id')
            ->havingRaw('sum(sales_points) >= ?', [$settings['dm_rep_points']])
            ->count();
        $eligible = 0;
        foreach (User::query()->whereIn('id', $referred)->get() as $rep) {
            $eval = $this->evaluate($rep, 'sales_manager');
            $objective = collect($eval)->where('code', '!=', 'senior_assessment');
            if ($objective->every(fn ($c) => $c['passed'])) {
                $eligible++;
            }
        }

        return [
            ['code' => 'tenure_years', 'required' => $settings['dm_years'], 'actual' => round($years, 3), 'passed' => $years >= $settings['dm_years']],
            ['code' => 'registered_reps', 'required' => $settings['dm_new_reps'], 'actual' => $referred->count(), 'passed' => $referred->count() >= $settings['dm_new_reps']],
            ['code' => 'strong_reps', 'required' => $settings['dm_strong_reps'], 'actual' => $strong, 'passed' => $strong >= $settings['dm_strong_reps']],
            ['code' => 'team_satisfaction', 'required' => 1, 'actual' => 0, 'passed' => false],
            ['code' => 'eligible_sales_managers', 'required' => $settings['dm_eligible_sms'], 'actual' => $eligible, 'passed' => $eligible >= $settings['dm_eligible_sms']],
            $this->trainingCriterion($user, 'sales_manager'),
            ['code' => 'senior_assessment', 'required' => 1, 'actual' => 0, 'passed' => false],
        ];
    }

    private function trainingCriterion(User $user, string $fromSlug): array
    {
        $role = Role::query()->where('slug', $fromSlug)->first();
        $query = Course::query()
            ->where('is_active', true)
            ->where('is_required_for_promotion', true)
            ->with('levels');

        if ($role) {
            $query->whereHas('roles', fn ($q) => $q->where('roles.id', $role->id));
        }

        $courses = $query->get();
        $required = $courses->sum(fn (Course $course) => $course->levels->count());
        if ($required === 0) {
            return ['code' => 'required_training', 'required' => 0, 'actual' => 0, 'passed' => true];
        }

        $done = 0;
        foreach ($courses as $course) {
            $done += UserCourseProgress::query()
                ->where('user_id', $user->id)
                ->where('course_id', $course->id)
                ->where('status', 'completed')
                ->whereIn('course_level_id', $course->levels->pluck('id'))
                ->count();
        }

        return [
            'code' => 'required_training',
            'required' => $required,
            'actual' => $done,
            'passed' => $done >= $required,
        ];
    }

    public function autoSubmitIfEligible(User $user): ?PromotionRequest
    {
        if ($user->isSuperuser() || $user->hasRole('senior_manager')) {
            return null;
        }

        $target = $user->hasRole('sales_manager') ? 'development_manager' : 'sales_manager';
        if ($user->hasRole($target)) {
            return null;
        }

        $objective = collect($this->evaluate($user, $target))
            ->whereNotIn('code', ['senior_assessment', 'team_satisfaction']);
        if ($objective->isEmpty() || ! $objective->every(fn ($row) => $row['passed'])) {
            return null;
        }

        $exists = PromotionRequest::query()
            ->where('user_id', $user->id)
            ->whereHas('targetRole', fn ($q) => $q->where('slug', $target))
            ->whereIn('status', ['pending', 'approved'])
            ->exists();
        if ($exists) {
            return null;
        }

        $from = $user->hasRole('sales_manager') ? 'sales_manager' : 'representative';

        return $this->request($user, $from, $target);
    }

    public function request(User $user, string $fromSlug, string $targetSlug): PromotionRequest
    {
        $from = Role::query()->where('slug', $fromSlug)->firstOrFail();
        $target = Role::query()->where('slug', $targetSlug)->firstOrFail();

        $request = PromotionRequest::query()->create([
            'user_id' => $user->id,
            'from_role_id' => $from->id,
            'target_role_id' => $target->id,
            'status' => 'pending',
            'submitted_at' => now(),
        ]);

        foreach ($this->evaluate($user, $targetSlug) as $row) {
            PromotionCriteriaResult::query()->create([
                'promotion_request_id' => $request->id,
                'criterion_code' => $row['code'],
                'required_value' => $row['required'],
                'actual_value' => $row['actual'],
                'passed' => $row['passed'],
                'evidence' => $row,
            ]);
        }

        Role::query()->where('slug', 'senior_manager')->first()?->users()
            ->wherePivot('is_active', true)
            ->get()
            ->each(function (User $senior) use ($user, $target, $request) {
                Notification::query()->create([
                    'user_id' => $senior->id,
                    'type' => 'promotion.pending',
                    'title' => 'درخواست ارتقاء جدید',
                    'body' => "{$user->name} واجد بررسی ارتقاء به {$target->name} است.",
                    'data' => ['user_id' => $user->id, 'promotion_id' => $request->id, 'path' => 'promotions'],
                ]);
            });

        return $request->load(['criteria', 'fromRole', 'targetRole']);
    }

    public function decide(User $reviewer, PromotionRequest $request, string $decision, string $note = ''): PromotionRequest
    {
        if (! $reviewer->hasRole('senior_manager') && ! $reviewer->isSuperuser()) {
            abort(403, 'فقط مدیر ارشد می‌تواند ارتقاء را تایید کند.');
        }
        if ($decision === 'rejected' && trim($note) === '') {
            abort(422, 'علت رد ارتقاء الزامی است.');
        }

        return DB::transaction(function () use ($reviewer, $request, $decision, $note) {
            $request->feedback()->create([
                'reviewer_user_id' => $reviewer->id,
                'decision' => $decision,
                'note' => $note,
            ]);
            $request->status = $decision === 'approved' ? 'approved' : 'rejected';
            $request->decided_at = now();
            $request->save();

            if ($decision === 'approved') {
                UserRole::query()->firstOrCreate(
                    [
                        'user_id' => $request->user_id,
                        'role_id' => $request->target_role_id,
                    ],
                    [
                        'effective_from' => now()->toDateString(),
                        'is_primary' => false,
                        'is_active' => true,
                    ]
                );
                $this->wallets->walletFor($request->user, $request->targetRole);
                $parent = $this->tree->activeNodesFor($reviewer)->first();
                $targetSlug = $request->targetRole?->slug;
                if ($targetSlug === 'sales_manager') {
                    $parent = $this->tree->activeNodesFor($reviewer, 'development_manager')->first()
                        ?? $this->tree->activeNodesFor($reviewer, 'senior_manager')->first()
                        ?? $parent;
                } elseif ($targetSlug === 'development_manager') {
                    $parent = $this->tree->activeNodesFor($reviewer, 'senior_manager')->first() ?? $parent;
                }
                $newNode = $this->tree->attach($request->user, $request->targetRole, $parent, now()->toDateString());

                if ($targetSlug === 'sales_manager') {
                    $this->tree->rehomeUnderNewSalesManager($request->user, $newNode);
                } elseif ($targetSlug === 'development_manager') {
                    $this->tree->nestLowerRoleNodesUnder($request->user, $newNode);
                } elseif ($targetSlug === 'senior_manager') {
                    $this->tree->nestLowerRoleNodesUnder($request->user, $newNode);
                }
            }

            $this->audit->record($reviewer, 'promotion.'.$decision, $request);

            $targetName = $request->targetRole?->name ?? 'نقش جدید';
            Notification::query()->create([
                'user_id' => $request->user_id,
                'type' => 'promotion.'.$decision,
                'title' => $decision === 'approved' ? 'ارتقاء تایید شد' : 'ارتقاء رد شد',
                'body' => $decision === 'approved'
                    ? "درخواست ارتقاء شما به {$targetName} تایید شد."
                    : "درخواست ارتقاء شما به {$targetName} رد شد. علت: {$note}",
                'data' => [
                    'promotion_id' => $request->id,
                    'path' => 'promotions',
                    'note' => $note,
                    'decision' => $decision,
                ],
            ]);

            return $request->fresh(['criteria', 'feedback', 'user', 'targetRole']);
        });
    }

    private function defaults(): array
    {
        return [
            'sm_personal_points' => 10000,
            'sm_new_reps' => 60,
            'sm_strong_reps' => 24,
            'sm_rep_points' => 5000,
            'dm_years' => 1,
            'dm_new_reps' => 100,
            'dm_strong_reps' => 30,
            'dm_rep_points' => 10000,
            'dm_eligible_sms' => 2,
        ];
    }
}
