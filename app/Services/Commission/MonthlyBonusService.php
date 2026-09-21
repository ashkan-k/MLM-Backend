<?php

namespace App\Services\Commission;

use App\Models\Commission;
use App\Models\Role;
use App\Models\User;
use App\Services\Organization\OrganizationTreeService;
use App\Services\Wallet\WalletService;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Monthly role bonus (پاداش ماهانه):
 * - Per-tx commissions always use base %.
 * - Qualification points = 100 × successful gateway registrations this calendar month
 *   (rep: own; SM/DM: own + downline). Month counter resets each month.
 * - Hitting the month threshold permanently marks that month's counted gateways as bonus-eligible.
 * - Bonus = (qualified% − base%) × attributed profits on permanently eligible gateways (this month).
 * - Unpaid remainder (profits on non-eligible gateways) → senior_manager wallet.
 */
class MonthlyBonusService
{
    public function __construct(
        private readonly CommissionRuleResolver $rules,
        private readonly QualificationService $qualification,
        private readonly CommissionCalculator $calculator,
        private readonly WalletService $wallets,
        private readonly OrganizationTreeService $tree,
    ) {}

    public function refresh(User $user, string $roleSlug, ?CarbonInterface $at = null): ?Commission
    {
        $at = $at ? $at->copy() : now();
        $monthStart = $at->copy()->startOfMonth();
        $monthEnd = $at->copy()->endOfMonth();
        $monthKey = $monthStart->format('Y-m');

        $role = Role::query()->where('slug', $roleSlug)->first();
        if (! $role) {
            return null;
        }

        $version = $this->rules->resolve($roleSlug, $at);
        if (! $version || $version->qualified_percent === null) {
            return null;
        }

        $bonusPercent = $this->bonusDeltaPercent($version);
        if (Money::cmp($bonusPercent, '0') <= 0) {
            return null;
        }

        $progress = $this->qualification->progress($roleSlug, $user, $role->id, $at);
        if ((float) ($progress['required'] ?? 0) <= 0) {
            return null;
        }

        $key = "monthly-bonus:{$user->id}:{$role->id}:{$monthKey}";

        return DB::transaction(function () use ($user, $role, $roleSlug, $version, $bonusPercent, $at, $monthStart, $monthEnd, $key) {
            $existing = Commission::query()->where('idempotency_key', $key)->lockForUpdate()->first();

            // قفل دائمی درگاه‌های این ماه در صورت رسیدن به حد نصاب امتیاز
            $this->qualification->syncPermanentEligibilities($roleSlug, $user, $role->id, $at);

            $eligibleIds = $this->qualification->permanentEligibleSaleIds($user->id, $role->id);
            $profitSum = $eligibleIds === []
                ? '0.000'
                : $this->monthlyAttributedProfit($user->id, $role->id, $monthStart, $monthEnd, $eligibleIds);

            if ($eligibleIds === [] || Money::cmp($profitSum, '0') <= 0) {
                if ($existing && $existing->status === 'posted') {
                    $this->reverse($existing);
                }

                return $existing?->fresh();
            }

            $amount = $this->calculator->amount($profitSum, $bonusPercent);
            if (Money::cmp($amount, '0') <= 0) {
                if ($existing && $existing->status === 'posted') {
                    $this->reverse($existing);
                }

                return $existing?->fresh();
            }

            $wallet = $this->wallets->walletFor($user, $role);
            $meta = [
                'type' => 'monthly_bonus',
                'month' => $monthStart->format('Y-m'),
                'bonus_percent' => $bonusPercent,
                'qualified_percent' => Money::normalize((string) $version->qualified_percent),
                'base_percent' => Money::normalize((string) $version->percent),
                'profit_sum' => $profitSum,
                'eligible_gateways' => count($eligibleIds),
                'qualified' => true,
            ];

            if (! $existing) {
                $commission = Commission::query()->create([
                    'user_id' => $user->id,
                    'role_id' => $role->id,
                    'gateway_sale_id' => null,
                    'finopal_transaction_id' => null,
                    'rule_version_id' => $version->id,
                    'base_amount' => Money::normalize($profitSum, 3),
                    'commission_percent' => $bonusPercent,
                    'commission_amount' => $amount,
                    'status' => 'posted',
                    'idempotency_key' => $key,
                    'metadata' => $meta,
                ]);

                $this->wallets->credit(
                    $wallet,
                    $amount,
                    'monthly_bonus_credit',
                    'wallet-'.$key,
                    Commission::class,
                    $commission->id,
                    $meta
                );

                return $commission;
            }

            $previous = Money::normalize((string) $existing->commission_amount);
            $diff = Money::sub($amount, $previous);

            $existing->base_amount = Money::normalize($profitSum, 3);
            $existing->commission_percent = $bonusPercent;
            $existing->commission_amount = $amount;
            $existing->rule_version_id = $version->id;
            $existing->status = 'posted';
            $existing->metadata = $meta;
            $existing->save();

            if (Money::cmp($diff, '0') > 0) {
                $this->wallets->credit(
                    $wallet,
                    $diff,
                    'monthly_bonus_credit',
                    'wallet-'.$key.'-adj-'.md5($amount),
                    Commission::class,
                    $existing->id,
                    $meta + ['adjustment' => $diff]
                );
            } elseif (Money::cmp($diff, '0') < 0) {
                $debit = Money::sub($previous, $amount);
                try {
                    $this->wallets->debit(
                        $wallet,
                        $debit,
                        'monthly_bonus_adjust',
                        'wallet-'.$key.'-adj-'.md5($amount),
                        Commission::class,
                        $existing->id,
                        $meta + ['adjustment' => '-'.$debit]
                    );
                } catch (\Throwable) {
                    $existing->commission_amount = $previous;
                    $existing->save();
                }
            }

            return $existing->fresh();
        });
    }

    /**
     * @return int number of roles refreshed
     */
    public function refreshMonth(?CarbonInterface $at = null): int
    {
        $at = $at ? $at->copy() : now();
        $monthStart = $at->copy()->startOfMonth();
        $monthEnd = $at->copy()->endOfMonth();

        $pairs = Commission::query()
            ->select('user_id', 'role_id')
            ->where('created_at', '>=', $monthStart)
            ->where('created_at', '<=', $monthEnd)
            ->where('idempotency_key', 'not like', 'monthly-bonus:%')
            ->where('idempotency_key', 'not like', 'monthly-bonus-residual:%')
            ->groupBy('user_id', 'role_id')
            ->get();

        $count = 0;
        foreach ($pairs as $row) {
            $user = User::query()->find($row->user_id);
            $role = Role::query()->find($row->role_id);
            if (! $user || ! $role) {
                continue;
            }
            $this->refresh($user, $role->slug, $at);
            $count++;
        }

        $this->settleUnpaidToSenior($at);

        return $count;
    }

    /**
     * مابقی پاداش ماهانه پرداخت‌نشده (نرسیده به حد نصاب یا سود قبل از حد نصاب) → کیف مدیر ارشد.
     */
    public function settleUnpaidToSenior(?CarbonInterface $at = null): ?Commission
    {
        $at = $at ? $at->copy() : now();
        $monthStart = $at->copy()->startOfMonth();
        $monthEnd = $at->copy()->endOfMonth();
        $monthKey = $monthStart->format('Y-m');
        $bonusRoles = ['representative', 'sales_manager', 'development_manager'];

        $seniorRole = Role::query()->where('slug', 'senior_manager')->first();
        if (! $seniorRole) {
            return null;
        }
        $senior = User::query()
            ->whereHas('roles', fn ($q) => $q->where('slug', 'senior_manager'))
            ->orderBy('id')
            ->first();
        if (! $senior) {
            return null;
        }

        $residual = '0.000';
        $details = [];

        $pairs = Commission::query()
            ->select('user_id', 'role_id')
            ->where('created_at', '>=', $monthStart)
            ->where('created_at', '<=', $monthEnd)
            ->where('idempotency_key', 'not like', 'monthly-bonus:%')
            ->where('idempotency_key', 'not like', 'monthly-bonus-residual:%')
            ->whereHas('role', fn ($q) => $q->whereIn('slug', $bonusRoles))
            ->groupBy('user_id', 'role_id')
            ->get();

        foreach ($pairs as $row) {
            $user = User::query()->find($row->user_id);
            $role = Role::query()->find($row->role_id);
            if (! $user || ! $role || ! in_array($role->slug, $bonusRoles, true)) {
                continue;
            }

            $version = $this->rules->resolve($role->slug, $at);
            if (! $version) {
                continue;
            }
            $delta = $this->bonusDeltaPercent($version);
            if (Money::cmp($delta, '0') <= 0) {
                continue;
            }

            $this->qualification->syncPermanentEligibilities($role->slug, $user, $role->id, $at);
            $eligibleIds = $this->qualification->permanentEligibleSaleIds($user->id, $role->id);
            $fullProfit = $this->monthlyAttributedProfit($user->id, $role->id, $monthStart, $monthEnd);
            if (Money::cmp($fullProfit, '0') <= 0) {
                continue;
            }

            $bonusProfit = $eligibleIds === []
                ? '0.000'
                : $this->monthlyAttributedProfit($user->id, $role->id, $monthStart, $monthEnd, $eligibleIds);
            $unpaidProfit = Money::sub($fullProfit, $bonusProfit);

            if (Money::cmp($unpaidProfit, '0') <= 0) {
                continue;
            }

            $piece = $this->calculator->amount($unpaidProfit, $delta);
            $residual = Money::add($residual, $piece);
            $details[] = [
                'user_id' => $user->id,
                'role' => $role->slug,
                'unpaid_profit' => $unpaidProfit,
                'amount' => $piece,
            ];
        }

        $key = "monthly-bonus-residual:senior:{$monthKey}";

        return DB::transaction(function () use ($senior, $seniorRole, $residual, $details, $key, $monthKey, $at) {
            $existing = Commission::query()->where('idempotency_key', $key)->lockForUpdate()->first();
            if (Money::cmp($residual, '0') <= 0) {
                if ($existing && $existing->status === 'posted') {
                    $this->reverse($existing);
                }

                return $existing?->fresh();
            }

            $version = $this->rules->resolve('senior_manager', $at);
            $meta = [
                'type' => 'monthly_bonus_residual',
                'month' => $monthKey,
                'details' => $details,
            ];
            $wallet = $this->wallets->walletFor($senior, $seniorRole);

            if (! $existing) {
                $commission = Commission::query()->create([
                    'user_id' => $senior->id,
                    'role_id' => $seniorRole->id,
                    'gateway_sale_id' => null,
                    'finopal_transaction_id' => null,
                    'rule_version_id' => $version?->id,
                    'base_amount' => Money::normalize($residual, 3),
                    'commission_percent' => '0.000',
                    'commission_amount' => Money::normalize($residual, 3),
                    'status' => 'posted',
                    'idempotency_key' => $key,
                    'metadata' => $meta,
                ]);
                $this->wallets->credit(
                    $wallet,
                    Money::normalize($residual, 3),
                    'monthly_bonus_residual',
                    'wallet-'.$key,
                    Commission::class,
                    $commission->id,
                    $meta
                );

                return $commission;
            }

            $previous = Money::normalize((string) $existing->commission_amount);
            $diff = Money::sub($residual, $previous);
            $existing->base_amount = Money::normalize($residual, 3);
            $existing->commission_amount = Money::normalize($residual, 3);
            $existing->status = 'posted';
            $existing->metadata = $meta;
            $existing->save();

            if (Money::cmp($diff, '0') > 0) {
                $this->wallets->credit(
                    $wallet,
                    $diff,
                    'monthly_bonus_residual',
                    'wallet-'.$key.'-adj-'.md5($residual),
                    Commission::class,
                    $existing->id,
                    $meta + ['adjustment' => $diff]
                );
            }

            return $existing->fresh();
        });
    }

    public function preview(User $user, string $roleSlug, ?CarbonInterface $at = null): array
    {
        $at = $at ? $at->copy() : now();
        $monthStart = $at->copy()->startOfMonth();
        $monthEnd = $at->copy()->endOfMonth();
        $role = Role::query()->where('slug', $roleSlug)->first();
        if (! $role) {
            return ['eligible' => false, 'qualified' => false, 'profit_sum' => '0.000', 'bonus_percent' => '0.000', 'bonus_amount' => '0.000'];
        }

        $version = $this->rules->resolve($roleSlug, $at);
        $progress = $this->qualification->progress($roleSlug, $user, $role->id, $at);
        $bonusPercent = $version ? $this->bonusDeltaPercent($version) : '0.000';
        $required = (float) ($progress['required'] ?? 0);
        $this->qualification->syncPermanentEligibilities($roleSlug, $user, $role->id, $at);
        $eligibleIds = $this->qualification->permanentEligibleSaleIds($user->id, $role->id);
        $monthMet = $this->qualification->monthThresholdMet($roleSlug, $user, $role->id, $at);
        $qualified = $eligibleIds !== [];
        $profitSum = $qualified
            ? $this->monthlyAttributedProfit($user->id, $role->id, $monthStart, $monthEnd, $eligibleIds)
            : '0.000';
        $fullProfit = $this->monthlyAttributedProfit($user->id, $role->id, $monthStart, $monthEnd);
        $bonusAmount = ($qualified && Money::cmp($bonusPercent, '0') > 0)
            ? $this->calculator->amount($profitSum, $bonusPercent)
            : '0.000';

        $guide = array_values(array_filter($progress['guide'] ?? []));
        $percentLabel = $this->formatPercentLabel($bonusPercent);
        if ($required > 0 && Money::cmp($bonusPercent, '0') > 0) {
            if ($qualified) {
                $guide[] = 'درگاه‌های واجد شرایط دائمی: '.count($eligibleIds)
                    .'؛ پاداش قابل واریز این ماه '
                    .Money::normalize($bonusAmount, 3)
                    .' ('.$percentLabel.' از سود تراکنش روی همان درگاه‌ها '
                    .Money::normalize($profitSum, 3)
                    .').';
            } elseif ($monthMet) {
                $guide[] = 'حد نصاب امتیاز این ماه تکمیل شده؛ با اولین به‌روزرسانی، درگاه‌های ماه جاری دائمی می‌شوند.';
            } else {
                $guide[] = 'پس از تکمیل حد نصاب امتیاز، درگاه‌های ثبت‌شدهٔ همین ماه برای همیشه واجد شرایط می‌شوند و '.$percentLabel
                    .' از سود تراکنش‌های بعدی آن‌ها پاداش می‌شود. در غیر این صورت پاداش بالقوه به مدیر ارشد می‌رود.';
            }
        }

        return [
            'eligible' => $required > 0 && Money::cmp($bonusPercent, '0') > 0,
            'qualified' => $qualified,
            'month_threshold_met' => $monthMet,
            'actual' => $progress['actual'] ?? 0,
            'required' => $progress['required'] ?? 0,
            'metric' => $progress['metric'] ?? null,
            'metric_label' => $progress['metric_label'] ?? null,
            'unit' => $progress['unit'] ?? null,
            'points_per_full_sale' => $progress['points_per_full_sale'] ?? null,
            'guide' => $guide,
            'profit_sum' => Money::normalize($profitSum, 3),
            'full_month_profit' => Money::normalize($fullProfit, 3),
            'bonus_percent' => $bonusPercent,
            'bonus_amount' => $bonusAmount,
            'eligible_gateways' => count($eligibleIds),
            'month' => $monthStart->format('Y-m'),
        ];
    }

    public function downlineMonitor(
        User $viewer,
        int $page = 1,
        int $perPage = 20,
        ?string $search = null,
        ?string $roleSlug = null,
        ?CarbonInterface $at = null,
    ): array {
        $at = $at ? $at->copy() : now();
        $monthKey = $at->copy()->startOfMonth()->format('Y-m');
        $perPage = max(1, min(50, $perPage));
        $bonusRoleSlugs = ['representative', 'sales_manager', 'development_manager'];
        if ($roleSlug && ! in_array($roleSlug, $bonusRoleSlugs, true)) {
            $roleSlug = null;
        }

        $paginator = $this->tree->paginateDescendants($viewer, $perPage, $search, $roleSlug);
        $paginator->appends([
            'search' => $search,
            'role_slug' => $roleSlug,
            'per_page' => $perPage,
        ]);

        $summary = [
            'month' => $monthKey,
            'downline_users' => $paginator->total(),
            'bonuses_posted' => 0,
            'bonuses_amount' => '0.000',
        ];

        $agg = $this->downlineBonusAggregate($viewer, $monthKey, $search, $roleSlug);
        $summary['bonuses_posted'] = $agg['count'];
        $summary['bonuses_amount'] = $agg['amount'];

        $roles = Role::query()->whereIn('slug', $bonusRoleSlugs)->get()->keyBy('slug');
        $rows = [];

        foreach ($paginator as $member) {
            /** @var User $member */
            $memberRoles = $member->roles()
                ->whereIn('slug', $bonusRoleSlugs)
                ->when($roleSlug, fn ($q) => $q->where('slug', $roleSlug))
                ->get();

            foreach ($memberRoles as $role) {
                if (! $roles->has($role->slug)) {
                    continue;
                }
                $preview = $this->preview($member, $role->slug, $at);
                if (! ($preview['eligible'] ?? false)) {
                    continue;
                }

                $posted = Commission::query()
                    ->where('user_id', $member->id)
                    ->where('role_id', $role->id)
                    ->where('status', 'posted')
                    ->where('idempotency_key', 'monthly-bonus:'.$member->id.':'.$role->id.':'.$monthKey)
                    ->first();

                $qualified = (bool) ($preview['qualified'] ?? false);
                $profitSum = Money::normalize((string) ($preview['profit_sum'] ?? '0'), 3);
                $bonusPercent = Money::normalize((string) ($preview['bonus_percent'] ?? '0'));
                $bonusAmount = $posted
                    ? Money::normalize((string) $posted->commission_amount)
                    : Money::normalize((string) ($preview['bonus_amount'] ?? '0'), 3);

                $payBlockedReason = null;
                if ($qualified && $posted === null) {
                    if (Money::cmp($bonusPercent, '0') <= 0) {
                        $payBlockedReason = 'درصد پاداش ماهانه این نقش صفر است.';
                    } elseif (Money::cmp($profitSum, '0') <= 0) {
                        $payBlockedReason = 'سود تراکنش‌های پس از حد نصاب صفر است.';
                    } elseif (Money::cmp($bonusAmount, '0') <= 0) {
                        $payBlockedReason = 'مبلغ پاداش محاسبه‌شده صفر است.';
                    }
                }
                $payable = $qualified && $posted === null && $payBlockedReason === null;

                $rows[] = [
                    'user_id' => $member->id,
                    'name' => $member->name,
                    'mobile' => $member->mobile,
                    'role_slug' => $role->slug,
                    'role_id' => $role->id,
                    'role_name' => $role->name,
                    'qualified' => $qualified,
                    'payable' => $payable,
                    'pay_blocked_reason' => $payBlockedReason,
                    'bonus_posted' => $posted !== null,
                    'actual' => $preview['actual'] ?? 0,
                    'required' => $preview['required'] ?? 0,
                    'metric_label' => $preview['metric_label'] ?? null,
                    'unit' => $preview['unit'] ?? null,
                    'bonus_percent' => $bonusPercent,
                    'bonus_amount' => $bonusAmount,
                    'profit_sum' => $profitSum,
                ];
            }
        }

        return [
            'summary' => $summary,
            'data' => $rows,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ];
    }

    public function payFor(User $actor, User $target, string $roleSlug, ?CarbonInterface $at = null): Commission
    {
        $at = $at ? $at->copy() : now();
        $bonusRoles = ['representative', 'sales_manager', 'development_manager'];
        if (! in_array($roleSlug, $bonusRoles, true)) {
            throw new \RuntimeException('این نقش پاداش ماهانه ندارد.');
        }
        if (! $actor->hasRole('senior_manager') && ! $actor->isSuperuser()) {
            throw new \RuntimeException('فقط مدیر ارشد می‌تواند پاداش را دستی واریز کند.');
        }
        if (! $actor->isSuperuser() && ! $this->tree->isDescendant($actor, $target) && $actor->id !== $target->id) {
            throw new \RuntimeException('این کاربر در زیرمجموعه شما نیست.');
        }
        if (! $target->hasRole($roleSlug)) {
            throw new \RuntimeException('کاربر این نقش را ندارد.');
        }

        $preview = $this->preview($target, $roleSlug, $at);
        if (! ($preview['qualified'] ?? false)) {
            throw new \RuntimeException('هنوز درگاه واجد شرایط دائمی برای پاداش این نقش وجود ندارد (حد نصاب امتیاز ماهانه کامل نشده).');
        }
        if (Money::cmp((string) ($preview['bonus_percent'] ?? '0'), '0') <= 0) {
            throw new \RuntimeException('درصد پاداش ماهانه این نقش صفر است؛ واریز ممکن نیست.');
        }
        if (Money::cmp((string) ($preview['profit_sum'] ?? '0'), '0') <= 0) {
            throw new \RuntimeException('سود تراکنش روی درگاه‌های واجد شرایط در این ماه صفر است؛ مبلغی برای واریز وجود ندارد.');
        }

        $commission = $this->refresh($target, $roleSlug, $at);
        if (! $commission || $commission->status !== 'posted') {
            throw new \RuntimeException('واریز پاداش انجام نشد؛ مبلغ پاداش قابل واریز نیست.');
        }

        return $commission;
    }

    /** @return array{count: int, amount: string} */
    private function downlineBonusAggregate(User $viewer, string $monthKey, ?string $search, ?string $roleSlug): array
    {
        $nodes = $this->tree->activeNodesFor($viewer);
        if ($nodes->isEmpty()) {
            return ['count' => 0, 'amount' => '0.000'];
        }

        $query = Commission::query()
            ->where('commissions.status', 'posted')
            ->where('commissions.idempotency_key', 'like', 'monthly-bonus:%:'.$monthKey)
            ->where('commissions.idempotency_key', 'not like', 'monthly-bonus-residual:%')
            ->whereExists(function ($q) use ($nodes) {
                $q->select(DB::raw(1))
                    ->from('organization_nodes as dn')
                    ->whereColumn('dn.user_id', 'commissions.user_id')
                    ->where('dn.is_active', true)
                    ->where(function ($outer) use ($nodes) {
                        foreach ($nodes as $node) {
                            $path = $node->path ?: '/'.$node->id.'/';
                            $outer->orWhere(function ($inner) use ($path, $node) {
                                $inner->where('dn.path', 'like', $path.'%')
                                    ->where('dn.id', '!=', $node->id);
                            });
                        }
                    });
            });

        if ($roleSlug) {
            $roleId = Role::query()->where('slug', $roleSlug)->value('id');
            if ($roleId) {
                $query->where('commissions.role_id', $roleId);
            }
        }

        if ($search) {
            $term = '%'.trim($search).'%';
            $query->whereHas('user', function ($q) use ($term) {
                $q->where('name', 'like', $term)->orWhere('mobile', 'like', $term);
            });
        }

        $agg = $query->selectRaw('COUNT(*) as cnt, COALESCE(SUM(commission_amount), 0) as total')->first();

        return [
            'count' => (int) ($agg->cnt ?? 0),
            'amount' => Money::normalize((string) ($agg->total ?? '0'), 3),
        ];
    }

    private function bonusDeltaPercent(object $version): string
    {
        $qualified = Money::normalize((string) ($version->qualified_percent ?? '0'));
        $base = Money::normalize((string) ($version->percent ?? '0'));
        $delta = Money::sub($qualified, $base);
        if (Money::cmp($delta, '0') < 0) {
            return '0.000';
        }

        return Money::normalize($delta, 3);
    }

    private function formatPercentLabel(string $percent): string
    {
        $normalized = Money::normalize($percent, 3);
        $rounded = round((float) $normalized, 2);
        if (abs($rounded - round($rounded)) < 0.0000001) {
            return ((string) (int) round($rounded)).'٪';
        }

        return rtrim(rtrim(number_format($rounded, 2, '.', ''), '0'), '.').'٪';
    }

    private function monthlyAttributedProfit(
        int $userId,
        int $roleId,
        CarbonInterface $from,
        CarbonInterface $to,
        ?array $onlyGatewaySaleIds = null,
    ): string {
        $query = Commission::query()
            ->where('user_id', $userId)
            ->where('role_id', $roleId)
            ->where('status', 'posted')
            ->where('created_at', '>=', $from)
            ->where('created_at', '<=', $to)
            ->where('idempotency_key', 'not like', 'monthly-bonus:%')
            ->where('idempotency_key', 'not like', 'monthly-bonus-residual:%');

        if ($onlyGatewaySaleIds !== null) {
            if ($onlyGatewaySaleIds === []) {
                return '0.000';
            }
            $query->whereIn('gateway_sale_id', $onlyGatewaySaleIds);
        }

        $rows = $query->get(['base_amount', 'metadata']);

        $sum = '0.000';
        foreach ($rows as $row) {
            $share = (string) ($row->metadata['share_percent'] ?? '100');
            $piece = Money::percentOf(Money::normalize((string) $row->base_amount, 3), $share);
            $sum = Money::add($sum, $piece);
        }

        return $sum;
    }

    private function reverse(Commission $commission): void
    {
        $wallet = $this->wallets->walletFor($commission->user, $commission->role);
        $amount = Money::normalize((string) $commission->commission_amount);
        if (Money::cmp($amount, '0') > 0) {
            try {
                $this->wallets->debit(
                    $wallet,
                    $amount,
                    'monthly_bonus_reversal',
                    'wallet-'.$commission->idempotency_key.'-rev-'.$commission->id,
                    Commission::class,
                    $commission->id
                );
            } catch (\Throwable) {
                return;
            }
        }
        $commission->status = 'reversed';
        $meta = $commission->metadata ?? [];
        $meta['reversed_at'] = now()->toIso8601String();
        $commission->metadata = $meta;
        $commission->save();
    }
}
