<?php

namespace App\Services\Report;

use App\Models\BenefitTransfer;
use App\Models\Commission;
use App\Models\GatewaySale;
use App\Models\Message;
use App\Models\PromotionRequest;
use App\Models\Role;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\Organization\OrganizationTreeService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class SuperuserReportService
{
    public function __construct(private OrganizationTreeService $tree) {}

    public function build(array $filters): array
    {
        [$from, $to] = $this->period($filters);
        $userIds = $this->scopedUserIds($filters);
        $roleId = filled($filters['role_id'] ?? null) ? (int) $filters['role_id'] : null;

        $sales = $this->salesQuery($from, $to, $userIds);
        $commissions = $this->commissionQuery($from, $to, $userIds, $roleId);
        $withdrawals = WithdrawalRequest::query()->whereBetween('requested_at', [$from, $to]);
        $promotions = PromotionRequest::query()->whereBetween('created_at', [$from, $to]);
        $transfers = BenefitTransfer::query()->whereBetween('created_at', [$from, $to]);
        $messages = Message::query()->whereBetween('created_at', [$from, $to]);

        if ($userIds !== null) {
            $withdrawals->whereIn('user_id', $userIds);
            $promotions->whereIn('user_id', $userIds);
            $transfers->where(fn ($q) => $q->whereIn('from_user_id', $userIds)->orWhereIn('to_user_id', $userIds));
            $messages->whereIn('sender_user_id', $userIds);
        }

        $salesRows = (clone $sales)->get();
        $commissionRows = (clone $commissions)->with(['user:id,name,mobile', 'role:id,name,slug'])->get();

        return [
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'role_id' => $roleId,
                'user_id' => filled($filters['user_id'] ?? null) ? (int) $filters['user_id'] : null,
                'include_descendants' => $this->bool($filters['include_descendants'] ?? true),
                'scoped_user_ids' => $userIds,
            ],
            'summary' => [
                ['key' => 'users', 'label' => 'کاربران در محدوده', 'value' => $userIds === null ? User::query()->count() : count($userIds)],
                ['key' => 'sales_count', 'label' => 'تعداد فروش درگاه', 'value' => $salesRows->count()],
                ['key' => 'sales_amount', 'label' => 'مبلغ فروش (تومان)', 'value' => (float) $salesRows->sum('amount'), 'money' => true],
                ['key' => 'commission_count', 'label' => 'تعداد پورسانت', 'value' => $commissionRows->count()],
                ['key' => 'commission_amount', 'label' => 'جمع پورسانت (تومان)', 'value' => (float) $commissionRows->sum('commission_amount'), 'money' => true],
                ['key' => 'withdrawals', 'label' => 'درخواست برداشت', 'value' => (clone $withdrawals)->count()],
                ['key' => 'promotions', 'label' => 'درخواست ارتقاء', 'value' => (clone $promotions)->count()],
                ['key' => 'transfers', 'label' => 'انتقال مزایا', 'value' => (clone $transfers)->count()],
                ['key' => 'messages', 'label' => 'پیام گفتگو', 'value' => (clone $messages)->count()],
            ],
            'operations' => [
                ['key' => 'sales', 'label' => 'فروش درگاه', 'count' => $salesRows->count(), 'amount' => (float) $salesRows->sum('amount')],
                ['key' => 'commissions', 'label' => 'ثبت پورسانت', 'count' => $commissionRows->count(), 'amount' => (float) $commissionRows->sum('commission_amount')],
                ['key' => 'withdrawals', 'label' => 'برداشت', 'count' => (clone $withdrawals)->count(), 'amount' => (float) (clone $withdrawals)->sum('amount')],
                ['key' => 'promotions', 'label' => 'ارتقاء', 'count' => (clone $promotions)->count(), 'amount' => 0],
                ['key' => 'transfers', 'label' => 'انتقال مزایا', 'count' => (clone $transfers)->count(), 'amount' => 0],
                ['key' => 'messages', 'label' => 'گفتگو', 'count' => (clone $messages)->count(), 'amount' => 0],
            ],
            'sales_over_time' => $this->groupByDay($salesRows, 'sold_at', 'amount'),
            'commissions_over_time' => $this->groupByDay($commissionRows, 'created_at', 'commission_amount'),
            'commissions_by_role' => $this->groupNamed($commissionRows, fn (Commission $row) => $row->role?->name ?? 'بدون نقش', 'commission_amount'),
            'commissions_by_user' => $commissionRows
                ->groupBy('user_id')
                ->map(function (Collection $rows) {
                    $first = $rows->first();

                    return [
                        'id' => $first?->user_id,
                        'label' => $first?->user?->name ?? 'نامشخص',
                        'mobile' => $first?->user?->mobile,
                        'count' => $rows->count(),
                        'value' => (float) $rows->sum('commission_amount'),
                    ];
                })
                ->sortByDesc('value')
                ->values()
                ->take(20)
                ->all(),
            'users_by_role' => Role::query()->withCount(['users' => fn ($q) => $q->where('user_roles.is_active', true)])->get()
                ->map(fn (Role $role) => [
                    'id' => $role->id,
                    'label' => $role->name,
                    'value' => $role->users_count,
                ])->values()->all(),
            'withdrawals_by_status' => (clone $withdrawals)->get()
                ->groupBy('status')
                ->map(fn (Collection $rows, string $status) => [
                    'label' => $status,
                    'count' => $rows->count(),
                    'value' => (float) $rows->sum('amount'),
                ])->values()->all(),
            'organization' => $this->organizationBreakdown($userIds),
            'recent_commissions' => $commissionRows->sortByDesc('id')->take(30)->values()->map(fn (Commission $row) => [
                'id' => $row->id,
                'user' => $row->user?->name,
                'mobile' => $row->user?->mobile,
                'role' => $row->role?->name,
                'amount' => (float) $row->commission_amount,
                'percent' => (float) $row->commission_percent,
                'created_at' => optional($row->created_at)->toIso8601String(),
            ])->all(),
        ];
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function period(array $filters): array
    {
        $from = filled($filters['from'] ?? null)
            ? Carbon::parse($filters['from'])->startOfDay()
            : now()->subDays(29)->startOfDay();
        $to = filled($filters['to'] ?? null)
            ? Carbon::parse($filters['to'])->endOfDay()
            : now()->endOfDay();

        return [$from, $to];
    }

    private function scopedUserIds(array $filters): ?array
    {
        if (! filled($filters['user_id'] ?? null)) {
            return null;
        }

        $ids = collect([(int) $filters['user_id']]);
        if ($this->bool($filters['include_descendants'] ?? true)) {
            $user = User::query()->find($filters['user_id']);
            if ($user) {
                $ids = $ids->merge($this->tree->descendants($user)->pluck('id'));
            }
        }

        return $ids->unique()->values()->all();
    }

    private function salesQuery(Carbon $from, Carbon $to, ?array $userIds)
    {
        $query = GatewaySale::query()->where('status', 'successful')->whereBetween('sold_at', [$from, $to]);
        if ($userIds !== null) {
            $query->where(function ($q) use ($userIds) {
                $q->whereHas('representatives', fn ($r) => $r->whereIn('user_id', $userIds))
                    ->orWhereHas('commissions', fn ($c) => $c->whereIn('user_id', $userIds));
            });
        }

        return $query;
    }

    private function commissionQuery(Carbon $from, Carbon $to, ?array $userIds, ?int $roleId)
    {
        $query = Commission::query()->whereBetween('created_at', [$from, $to]);
        if ($userIds !== null) {
            $query->whereIn('user_id', $userIds);
        }
        if ($roleId) {
            $query->where('role_id', $roleId);
        }

        return $query;
    }

    private function groupByDay(Collection $rows, string $dateField, string $amountField): array
    {
        return $rows
            ->groupBy(fn ($row) => optional($row->{$dateField})?->toDateString() ?? now()->toDateString())
            ->sortKeys()
            ->map(fn (Collection $group, string $day) => [
                'label' => $day,
                'count' => $group->count(),
                'value' => (float) $group->sum($amountField),
            ])
            ->values()
            ->all();
    }

    private function groupNamed(Collection $rows, callable $label, string $amountField): array
    {
        return $rows
            ->groupBy($label)
            ->map(fn (Collection $group, string $name) => [
                'label' => $name,
                'count' => $group->count(),
                'value' => (float) $group->sum($amountField),
            ])
            ->values()
            ->all();
    }

    private function organizationBreakdown(?array $userIds): array
    {
        $users = User::query()
            ->with(['roles:id,name,slug'])
            ->when($userIds, fn ($q) => $q->whereIn('id', $userIds))
            ->orderBy('id')
            ->get();

        return $users->map(function (User $user) {
            $descendants = $this->tree->descendants($user);

            return [
                'id' => $user->id,
                'name' => $user->name,
                'mobile' => $user->mobile,
                'roles' => $user->roles->pluck('name')->all(),
                'descendant_count' => $descendants->count(),
                'descendants' => $descendants->map(fn (User $child) => [
                    'id' => $child->id,
                    'name' => $child->name,
                    'mobile' => $child->mobile,
                ])->values()->all(),
            ];
        })->all();
    }

    private function bool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
