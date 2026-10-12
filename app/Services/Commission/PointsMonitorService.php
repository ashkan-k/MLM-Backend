<?php

namespace App\Services\Commission;

use App\Models\GatewayBonusEligibility;
use App\Models\GatewayManager;
use App\Models\GatewayRepresentative;
use App\Models\GatewaySale;
use App\Models\Commission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserBonusDefault;
use App\Models\UserPointAdjustment;
use App\Services\Audit\AuditService;
use App\Services\Organization\OrganizationTreeService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PointsMonitorService
{
    private const POINT_ROLES = ['representative', 'sales_manager', 'development_manager'];

    public function __construct(
        private readonly QualificationService $qualification,
        private readonly OrganizationTreeService $tree,
        private readonly AuditService $audit,
        private readonly MonthlyBonusService $bonuses,
    ) {}

    public function assertMonitorAccess(User $viewer): void
    {
        if ($viewer->isSuperuser() || $viewer->hasRole('senior_manager')) {
            return;
        }

        abort(403, 'فقط سوپریوزر و مدیر ارشد به مانیتورینگ امتیاز دسترسی دارند.');
    }

    /**
     * درخت سازمان با امتیاز ماهانه روی هر گره (نقش قابل‌نمایش همان گره).
     *
     * @return array{month: string, points_per_gateway: int, thresholds: array, summary: array, tree: array}
     */
    public function monitor(User $viewer, ?string $monthKey = null, ?string $search = null): array
    {
        $this->assertMonitorAccess($viewer);

        $at = $this->resolveMonth($monthKey);
        $key = $at->format('Y-m');
        $ppg = $this->qualification->pointsPerGateway();
        $thresholds = $this->qualification->thresholds();

        $rawTree = $viewer->isSuperuser() || $viewer->hasRole('senior_manager')
            ? $this->tree->tree()
            : [];

        $roles = Role::query()->whereIn('slug', self::POINT_ROLES)->get()->keyBy('slug');
        $bonusDefaults = UserBonusDefault::query()
            ->get(['user_id', 'role_id'])
            ->mapWithKeys(fn (UserBonusDefault $row) => [$row->user_id.':'.$row->role_id => true]);

        $annotate = function (array $nodes) use (&$annotate, $at, $ppg, $bonusDefaults): array {
            $out = [];
            foreach ($nodes as $node) {
                $user = $node['user'] ?? null;
                $role = $node['role'] ?? null;
                $slug = is_array($role) ? ($role['slug'] ?? null) : ($role?->slug ?? null);
                $roleId = is_array($role) ? ($role['id'] ?? null) : ($role?->id ?? null);
                $roleName = is_array($role) ? ($role['name'] ?? '') : ($role?->name ?? '');

                $points = null;
                if ($user && $slug && in_array($slug, self::POINT_ROLES, true) && $roleId) {
                    $userModel = $user instanceof User ? $user : User::query()->find((int) (is_array($user) ? $user['id'] : $user->id));
                    if ($userModel) {
                        $gatewayIds = $this->qualification->monthGatewayIds($slug, $userModel, (int) $roleId, $at);
                        $gatewayPoints = count($gatewayIds) * $ppg;
                        $manualPoints = $this->qualification->manualPoints($userModel, (int) $roleId, $at);
                        $actual = $gatewayPoints + $manualPoints;
                        $required = $this->qualification->requiredPoints($slug);
                        $qualified = $required > 0 && $actual >= $required;
                        $eligibleCount = GatewayBonusEligibility::query()
                            ->where('user_id', $userModel->id)
                            ->where('role_id', $roleId)
                            ->count();

                        $points = [
                            'role_slug' => $slug,
                            'role_name' => $roleName,
                            'role_id' => (int) $roleId,
                            'gateway_count' => count($gatewayIds),
                            'gateway_points' => $gatewayPoints,
                            'manual_points' => $manualPoints,
                            'actual' => $actual,
                            'required' => $required,
                            'progress_pct' => $required > 0 ? min(100, (int) round(($actual / $required) * 100)) : 0,
                            'threshold_met' => $qualified,
                            'permanent_eligible_count' => $eligibleCount,
                            'bonus_default' => $bonusDefaults->has($userModel->id.':'.$roleId),
                        ];
                    }
                }

                $children = $annotate($node['children'] ?? []);
                $out[] = [
                    'id' => $node['id'],
                    'user' => [
                        'id' => is_array($user) ? ($user['id'] ?? null) : ($user?->id),
                        'name' => is_array($user) ? ($user['name'] ?? '—') : ($user?->name ?? '—'),
                        'mobile' => is_array($user) ? ($user['mobile'] ?? null) : ($user?->mobile),
                        'is_active' => is_array($user) ? (bool) ($user['is_active'] ?? true) : (bool) ($user?->is_active ?? true),
                    ],
                    'role' => [
                        'id' => $roleId,
                        'name' => $roleName,
                        'slug' => $slug,
                    ],
                    'points' => $points,
                    'descendant_count' => $node['descendant_count'] ?? count($children),
                    'children' => $children,
                ];
            }

            return $out;
        };

        $tree = $annotate($rawTree);

        // نقش‌های پنهان (مثلاً SM وقتی DM نقش بالاتر است) را در لیست تخت هم بدهیم
        $flatRoles = $this->flatRoleSnapshots($viewer, $at, $roles, $ppg);

        if ($search) {
            $needle = mb_strtolower(trim($search));
            $flatRoles = array_values(array_filter(
                $flatRoles,
                static function (array $row) use ($needle): bool {
                    $hay = mb_strtolower(implode(' ', [
                        $row['name'] ?? '',
                        $row['mobile'] ?? '',
                        $row['role_name'] ?? '',
                        (string) ($row['user_id'] ?? ''),
                    ]));

                    return str_contains($hay, $needle);
                }
            ));
            $tree = $this->filterTree($tree, $search);
        }

        $summary = [
            'month' => $key,
            'scored_nodes' => count(array_filter($flatRoles, static fn (array $r) => ($r['actual'] ?? 0) > 0)),
            'total_points' => (int) array_sum(array_column($flatRoles, 'actual')),
            'gateway_points' => (int) array_sum(array_column($flatRoles, 'gateway_points')),
            'manual_points' => (int) array_sum(array_column($flatRoles, 'manual_points')),
            'qualified_nodes' => count(array_filter($flatRoles, static fn (array $r) => ! empty($r['threshold_met']))),
            'role_rows' => count($flatRoles),
        ];

        return [
            'month' => $key,
            'points_per_gateway' => $ppg,
            'thresholds' => $thresholds,
            'summary' => $summary,
            'tree' => $tree,
            'roles_flat' => $flatRoles,
        ];
    }

    /**
     * جزئیات امتیاز یک کاربر/نقش: درگاه‌های ماه + دستی + واجد شرایط دائمی.
     */
    public function detail(User $viewer, int $userId, string $roleSlug, ?string $monthKey = null): array
    {
        $this->assertMonitorAccess($viewer);

        if (! in_array($roleSlug, self::POINT_ROLES, true)) {
            throw ValidationException::withMessages(['role_slug' => 'نقش نامعتبر است.']);
        }

        $target = User::query()->findOrFail($userId);
        if (! $viewer->isSuperuser()) {
            $allowed = $this->tree->descendantUserIds($viewer)->push($viewer->id)->all();
            if (! in_array($target->id, $allowed, true)) {
                abort(403, 'این کاربر در زیرمجموعه شما نیست.');
            }
        }

        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        $at = $this->resolveMonth($monthKey);
        $key = $at->format('Y-m');
        $ppg = $this->qualification->pointsPerGateway();

        $gatewayRows = $this->gatewayBreakdown($roleSlug, $target, (int) $role->id, $at, $ppg);
        $gatewayPoints = count($gatewayRows) * $ppg;
        $manualPoints = $this->qualification->manualPoints($target, (int) $role->id, $at);
        $actual = $gatewayPoints + $manualPoints;
        $required = $this->qualification->requiredPoints($roleSlug);

        $adjustments = UserPointAdjustment::query()
            ->with('actor:id,name,mobile')
            ->where('user_id', $target->id)
            ->where('role_id', $role->id)
            ->where('month_key', $key)
            ->latest('id')
            ->get()
            ->map(fn (UserPointAdjustment $row) => [
                'id' => $row->id,
                'delta_points' => $row->delta_points,
                'note' => $row->note,
                'month_key' => $row->month_key,
                'actor' => $row->actor ? [
                    'id' => $row->actor->id,
                    'name' => $row->actor->name,
                ] : null,
                'created_at' => $row->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $eligibilities = GatewayBonusEligibility::query()
            ->with(['sale.gateway', 'sale.customer'])
            ->where('user_id', $target->id)
            ->where('role_id', $role->id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (GatewayBonusEligibility $e) => [
                'gateway_sale_id' => $e->gateway_sale_id,
                'qualified_month' => $e->qualified_month,
                'gateway_name' => $e->sale?->gateway?->name,
                'merchant_code' => $e->sale?->gateway?->merchant_code,
                'sold_at' => $e->sale?->sold_at?->toIso8601String(),
                'customer_name' => $e->sale?->customer?->name,
            ])
            ->values()
            ->all();

        return [
            'month' => $key,
            'points_per_gateway' => $ppg,
            'user' => [
                'id' => $target->id,
                'name' => $target->name,
                'mobile' => $target->mobile,
            ],
            'role' => [
                'id' => $role->id,
                'slug' => $role->slug,
                'name' => $role->name,
            ],
            'totals' => [
                'gateway_count' => count($gatewayRows),
                'gateway_points' => $gatewayPoints,
                'manual_points' => $manualPoints,
                'actual' => $actual,
                'required' => $required,
                'progress_pct' => $required > 0 ? min(100, (int) round(($actual / $required) * 100)) : 0,
                'threshold_met' => $required > 0 && $actual >= $required,
                'permanent_eligible_count' => count($eligibilities),
            ],
            'gateways' => $gatewayRows,
            'adjustments' => $adjustments,
            'permanent_eligibilities' => $eligibilities,
            'can_adjust' => $viewer->isSuperuser(),
            'bonus_default' => $this->qualification->hasBonusDefault($target->id, (int) $role->id),
            'can_grant_bonus_default' => true,
        ];
    }

    /**
     * Users the viewer may grant a default bonus to, with one flag per bonus role.
     *
     * @return list<array{id: int, name: string, mobile: ?string, roles: list<array{slug: string, name: string, bonus_default: bool}>}>
     */
    public function listBonusDefaults(User $viewer, ?string $search = null, ?int $userId = null): array
    {
        $this->assertMonitorAccess($viewer);

        $query = User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->whereIn('slug', self::POINT_ROLES));

        if (! $viewer->isSuperuser()) {
            $ids = $this->tree->descendantUserIds($viewer)->push($viewer->id)->all();
            $query->whereIn('id', $ids);
        }
        if ($userId) {
            $query->where('id', $userId);
        }
        if ($search && trim($search) !== '') {
            $term = '%'.trim($search).'%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)->orWhere('mobile', 'like', $term);
            });
        }

        $users = $query
            ->with(['roles' => fn ($q) => $q->whereIn('slug', self::POINT_ROLES)])
            ->orderBy('name')
            ->limit($userId ? 1 : 20)
            ->get();

        $defaults = UserBonusDefault::query()
            ->whereIn('user_id', $users->pluck('id'))
            ->get(['user_id', 'role_id'])
            ->mapWithKeys(fn (UserBonusDefault $row) => [$row->user_id.':'.$row->role_id => true]);

        return $users->map(function (User $user) use ($defaults) {
            return [
                'id' => $user->id,
                'name' => $user->name,
                'mobile' => $user->mobile,
                'roles' => $user->roles->map(fn (Role $role) => [
                    'slug' => $role->slug,
                    'name' => $role->name,
                    'bonus_default' => $defaults->has($user->id.':'.$role->id),
                ])->values()->all(),
            ];
        })->all();
    }

    /**
     * Turn the role's monthly bonus on by default for every past and future counted gateway.
     *
     * @return array{enabled: bool, locked_gateways: int}
     */
    public function setBonusDefault(User $actor, int $userId, string $roleSlug, bool $enabled): array
    {
        $this->assertMonitorAccess($actor);

        if (! in_array($roleSlug, self::POINT_ROLES, true)) {
            throw ValidationException::withMessages(['role_slug' => 'نقش نامعتبر است.']);
        }

        $target = User::query()->findOrFail($userId);
        if (! $actor->isSuperuser()) {
            $allowed = $this->tree->descendantUserIds($actor)->push($actor->id)->all();
            if (! in_array($target->id, $allowed, true)) {
                abort(403, 'این کاربر در زیرمجموعه شما نیست.');
            }
        }

        if (! $target->hasRole($roleSlug)) {
            throw ValidationException::withMessages([
                'role_slug' => 'این کاربر نقش انتخاب‌شده را ندارد.',
            ]);
        }

        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();

        return DB::transaction(function () use ($actor, $target, $role, $roleSlug, $enabled) {
            if ($enabled) {
                UserBonusDefault::query()->firstOrCreate(
                    [
                        'user_id' => $target->id,
                        'role_id' => $role->id,
                    ],
                    ['granted_by' => $actor->id]
                );
                $locked = $this->qualification->syncPermanentEligibilities($roleSlug, $target, (int) $role->id, now());
                foreach ($this->bonusMonths($target->id, (int) $role->id) as $at) {
                    $this->bonuses->refresh($target, $roleSlug, $at);
                    $this->bonuses->settleUnpaidToSenior($at);
                }
            } else {
                UserBonusDefault::query()
                    ->where('user_id', $target->id)
                    ->where('role_id', $role->id)
                    ->delete();
                $locked = 0;
            }

            $this->audit->record($actor, $enabled ? 'bonus.default_on' : 'bonus.default_off', $target, null, [
                'role_slug' => $roleSlug,
                'locked_gateways' => $locked,
            ]);

            return [
                'enabled' => $enabled,
                'locked_gateways' => $locked,
            ];
        });
    }

    /** @return list<CarbonInterface> */
    private function bonusMonths(int $userId, int $roleId): array
    {
        $keys = Commission::query()
            ->where('user_id', $userId)
            ->where('role_id', $roleId)
            ->where('idempotency_key', 'not like', 'monthly-bonus:%')
            ->where('idempotency_key', 'not like', 'monthly-bonus-residual:%')
            ->pluck('created_at')
            ->map(fn ($at) => Carbon::parse($at)->format('Y-m'))
            ->push(now()->format('Y-m'))
            ->unique()
            ->values();

        return $keys->map(function (string $key) {
            $start = Carbon::createFromFormat('Y-m', $key)->startOfMonth();

            return $start->isSameMonth(now()) ? now() : $start->copy()->endOfMonth();
        })->all();
    }

    public function adjust(
        User $actor,
        int $userId,
        string $roleSlug,
        int $deltaPoints,
        ?string $monthKey = null,
        ?string $note = null,
    ): UserPointAdjustment {
        if (! $actor->isSuperuser()) {
            abort(403, 'فقط سوپریوزر می‌تواند امتیاز را دستی ویرایش کند.');
        }
        if (! in_array($roleSlug, self::POINT_ROLES, true)) {
            throw ValidationException::withMessages(['role_slug' => 'نقش نامعتبر است.']);
        }
        if ($deltaPoints === 0) {
            throw ValidationException::withMessages(['delta_points' => 'مقدار تغییر نمی‌تواند صفر باشد.']);
        }

        $target = User::query()->findOrFail($userId);
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        $at = $this->resolveMonth($monthKey);
        $key = $at->format('Y-m');

        $row = DB::transaction(function () use ($actor, $target, $role, $deltaPoints, $key, $note, $roleSlug, $at) {
            $created = UserPointAdjustment::query()->create([
                'user_id' => $target->id,
                'role_id' => $role->id,
                'delta_points' => $deltaPoints,
                'month_key' => $key,
                'note' => $note,
                'actor_user_id' => $actor->id,
            ]);

            // اگر با این تغییر حد نصاب تکمیل شد، درگاه‌های ماه را قفل دائمی کن
            $this->qualification->syncPermanentEligibilities($roleSlug, $target, (int) $role->id, $at);

            $this->audit->record($actor, 'points.manual_adjust', $target, null, [
                'role_slug' => $roleSlug,
                'delta_points' => $deltaPoints,
                'month_key' => $key,
                'note' => $note,
                'adjustment_id' => $created->id,
            ]);

            return $created;
        });

        return $row->load('actor:id,name');
    }

    private function resolveMonth(?string $monthKey): CarbonInterface
    {
        if ($monthKey && preg_match('/^\d{4}-\d{2}$/', $monthKey)) {
            $start = Carbon::createFromFormat('Y-m', $monthKey)->startOfMonth();
            $end = $start->copy()->endOfMonth();
            // برای ماه جاری تا «الان»، برای ماه‌های گذشته تا پایان همان ماه
            if ($start->isSameMonth(now())) {
                return now();
            }

            return $end;
        }

        return now();
    }

    private function filterTree(array $nodes, string $q): array
    {
        $needle = mb_strtolower(trim($q));
        if ($needle === '') {
            return $nodes;
        }

        $out = [];
        foreach ($nodes as $node) {
            $kids = $this->filterTree($node['children'] ?? [], $q);
            $hay = mb_strtolower(implode(' ', [
                $node['user']['name'] ?? '',
                $node['user']['mobile'] ?? '',
                $node['role']['name'] ?? '',
                (string) ($node['user']['id'] ?? ''),
            ]));
            if (str_contains($hay, $needle) || $kids !== []) {
                $out[] = [...$node, 'children' => str_contains($hay, $needle) ? ($node['children'] ?? []) : $kids];
            }
        }

        return $out;
    }

    /**
     * @param  \Illuminate\Support\Collection<string, Role>  $roles
     * @return list<array>
     */
    private function flatRoleSnapshots(User $viewer, CarbonInterface $at, $roles, int $ppg): array
    {
        $query = User::query()->where('is_active', true)->whereHas('roles', fn ($q) => $q->whereIn('slug', self::POINT_ROLES));

        if (! $viewer->isSuperuser()) {
            $ids = $this->tree->descendantUserIds($viewer)->push($viewer->id)->all();
            $query->whereIn('id', $ids);
        }

        $users = $query->with(['roles' => fn ($q) => $q->whereIn('slug', self::POINT_ROLES)])->orderBy('id')->get();
        $bonusDefaults = UserBonusDefault::query()
            ->get(['user_id', 'role_id'])
            ->mapWithKeys(fn (UserBonusDefault $row) => [$row->user_id.':'.$row->role_id => true]);
        $rows = [];
        foreach ($users as $user) {
            foreach ($user->roles as $role) {
                if (! isset($roles[$role->slug])) {
                    continue;
                }
                $gatewayIds = $this->qualification->monthGatewayIds($role->slug, $user, (int) $role->id, $at);
                $gatewayPoints = count($gatewayIds) * $ppg;
                $manualPoints = $this->qualification->manualPoints($user, (int) $role->id, $at);
                $actual = $gatewayPoints + $manualPoints;
                $required = $this->qualification->requiredPoints($role->slug);
                $rows[] = [
                    'user_id' => $user->id,
                    'name' => $user->name,
                    'mobile' => $user->mobile,
                    'role_slug' => $role->slug,
                    'role_name' => $role->name,
                    'role_id' => (int) $role->id,
                    'gateway_count' => count($gatewayIds),
                    'gateway_points' => $gatewayPoints,
                    'manual_points' => $manualPoints,
                    'actual' => $actual,
                    'required' => $required,
                    'progress_pct' => $required > 0 ? min(100, (int) round(($actual / $required) * 100)) : 0,
                    'threshold_met' => $required > 0 && $actual >= $required,
                    'bonus_default' => $bonusDefaults->has($user->id.':'.$role->id),
                ];
            }
        }

        usort($rows, fn ($a, $b) => ($b['actual'] <=> $a['actual']) ?: ($a['name'] <=> $b['name']));

        return $rows;
    }

    /** @return list<array> */
    private function gatewayBreakdown(
        string $ruleCode,
        User $user,
        int $roleId,
        CarbonInterface $at,
        int $ppg,
    ): array {
        $ids = $this->qualification->monthGatewayIds($ruleCode, $user, $roleId, $at);
        if ($ids === []) {
            return [];
        }

        $sales = GatewaySale::query()
            ->with(['gateway', 'customer', 'representatives.user:id,name,mobile'])
            ->whereIn('id', $ids)
            ->orderByDesc('sold_at')
            ->get()
            ->keyBy('id');

        $asManager = [];
        if (in_array($ruleCode, ['sales_manager', 'development_manager'], true)) {
            $asManager = GatewayManager::query()
                ->where('user_id', $user->id)
                ->where('role_id', $roleId)
                ->whereIn('gateway_sale_id', $ids)
                ->pluck('gateway_sale_id')
                ->map(fn ($id) => (int) $id)
                ->flip()
                ->all();
        }

        $asRep = GatewayRepresentative::query()
            ->where('user_id', $user->id)
            ->whereIn('gateway_sale_id', $ids)
            ->pluck('gateway_sale_id')
            ->map(fn ($id) => (int) $id)
            ->flip()
            ->all();

        $eligibleFlip = GatewayBonusEligibility::query()
            ->where('user_id', $user->id)
            ->where('role_id', $roleId)
            ->whereIn('gateway_sale_id', $ids)
            ->pluck('gateway_sale_id')
            ->map(fn ($id) => (int) $id)
            ->flip()
            ->all();

        $rows = [];
        foreach ($ids as $saleId) {
            /** @var GatewaySale|null $sale */
            $sale = $sales->get($saleId);
            $sources = [];
            if (isset($asRep[$saleId])) {
                $sources[] = ['code' => 'self_registration', 'label' => 'ثبت‌نام مستقیم خود کاربر'];
            }
            if (isset($asManager[$saleId])) {
                $sources[] = ['code' => 'manager_chain', 'label' => 'زنجیره مدیران درگاه'];
            }
            if ($sources === [] && $ruleCode !== 'representative') {
                $rep = $sale?->representatives->first();
                $sources[] = [
                    'code' => 'downline_registration',
                    'label' => 'ثبت‌نام زیرمجموعه',
                    'registrant' => $rep?->user ? [
                        'id' => $rep->user->id,
                        'name' => $rep->user->name,
                        'mobile' => $rep->user->mobile,
                    ] : null,
                ];
            }

            $rows[] = [
                'gateway_sale_id' => $saleId,
                'gateway_name' => $sale?->gateway?->name,
                'merchant_code' => $sale?->gateway?->merchant_code,
                'customer_name' => $sale?->customer?->name,
                'sold_at' => $sale?->sold_at?->toIso8601String(),
                'status' => $sale?->status,
                'points' => $ppg,
                'permanently_eligible' => isset($eligibleFlip[$saleId]),
                'sources' => $sources,
                'registrants' => ($sale?->representatives ?? collect())->map(fn ($r) => [
                    'id' => $r->user?->id,
                    'name' => $r->user?->name,
                    'mobile' => $r->user?->mobile,
                    'share_percent' => $r->share_percent ?? null,
                ])->values()->all(),
            ];
        }

        return $rows;
    }
}
