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
 * - Per-transaction commissions always use base percent.
 * - If the user meets that role's qualification thresholds for the calendar month,
 *   credit: monthly-bonus percent × sum(attributed transaction profits this month).
 * - Recalculated within the month as profits grow; resets when the month changes.
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

        // درصد پاداش ماهانه (در پنل: «درصد پاداش ماهانه») × مجموع سود ماه
        $bonusPercent = Money::normalize((string) $version->qualified_percent);
        if (Money::cmp($bonusPercent, '0') <= 0) {
            return null;
        }

        // Roles without a monthly qualification metric never get this bonus
        $progress = $this->qualification->progress($roleSlug, $user, $role->id, $at);
        if ((float) ($progress['required'] ?? 0) <= 0) {
            return null;
        }

        $key = "monthly-bonus:{$user->id}:{$role->id}:{$monthKey}";

        return DB::transaction(function () use ($user, $role, $roleSlug, $version, $bonusPercent, $at, $monthStart, $monthEnd, $key) {
            $existing = Commission::query()->where('idempotency_key', $key)->lockForUpdate()->first();
            $qualified = $this->qualification->isQualified($roleSlug, $user, $role->id, $at);

            if (! $qualified) {
                if ($existing && $existing->status === 'posted') {
                    $this->reverse($existing);
                }

                return $existing?->fresh();
            }

            $profitSum = $this->monthlyAttributedProfit($user->id, $role->id, $monthStart, $monthEnd);
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
                'profit_sum' => $profitSum,
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
     * Refresh bonuses for every user/role that had non-bonus commissions in the month.
     *
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

        return $count;
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
        $bonusPercent = $version?->qualified_percent !== null
            ? Money::normalize((string) $version->qualified_percent)
            : '0.000';
        $required = (float) ($progress['required'] ?? 0);
        $qualified = $required > 0 && $this->qualification->isQualified($roleSlug, $user, $role->id, $at);
        $profitSum = $this->monthlyAttributedProfit($user->id, $role->id, $monthStart, $monthEnd);
        $bonusAmount = ($qualified && Money::cmp($bonusPercent, '0') > 0)
            ? $this->calculator->amount($profitSum, $bonusPercent)
            : '0.000';

        $guide = array_values(array_filter($progress['guide'] ?? []));
        $percentLabel = $this->formatPercentLabel($bonusPercent);
        if ($required > 0 && Money::cmp($bonusPercent, '0') > 0) {
            if ($qualified) {
                $guide[] = 'شما اکنون واجد شرایط پاداش این ماه هستید؛ مبلغ قابل واریز '
                    .Money::normalize($bonusAmount, 3)
                    .' ('.$percentLabel.' از مجموع سود ماه '
                    .Money::normalize($profitSum, 3)
                    .') است.';
            } else {
                $guide[] = 'پس از تکمیل حد نصاب، '.$percentLabel
                    .' از مجموع سود تراکنش‌های همین ماه به‌عنوان پاداش ماهانه محاسبه و واریز می‌شود.';
            }
        }

        return [
            'eligible' => $required > 0 && Money::cmp($bonusPercent, '0') > 0,
            'qualified' => $qualified,
            'actual' => $progress['actual'] ?? 0,
            'required' => $progress['required'] ?? 0,
            'metric' => $progress['metric'] ?? null,
            'metric_label' => $progress['metric_label'] ?? null,
            'unit' => $progress['unit'] ?? null,
            'points_per_full_sale' => $progress['points_per_full_sale'] ?? null,
            'guide' => $guide,
            'profit_sum' => Money::normalize($profitSum, 3),
            'bonus_percent' => $bonusPercent,
            'bonus_amount' => $bonusAmount,
            'month' => $monthStart->format('Y-m'),
        ];
    }

    /**
     * Lightweight downline bonus monitor for senior managers.
     * Summary respects the same search/role filters as the list; detail is paginated.
     *
     * @return array{summary: array, data: list<array>, meta: array}
     */
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
                        $payBlockedReason = 'سود تراکنش‌های این ماه برای این نقش صفر است.';
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

    /**
     * Senior manager manually settles monthly bonus for a downline user/role.
     */
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
            throw new \RuntimeException('کاربر هنوز به حد نصاب پاداش این ماه نرسیده است.');
        }
        if (Money::cmp((string) ($preview['bonus_percent'] ?? '0'), '0') <= 0) {
            throw new \RuntimeException('درصد پاداش ماهانه این نقش صفر است؛ واریز ممکن نیست.');
        }
        if (Money::cmp((string) ($preview['profit_sum'] ?? '0'), '0') <= 0) {
            throw new \RuntimeException('سود تراکنش‌های این ماه برای این نقش صفر است؛ مبلغی برای واریز وجود ندارد.');
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

    private function formatPercentLabel(string $percent): string
    {
        $normalized = Money::normalize($percent, 3);
        $rounded = round((float) $normalized, 2);
        if (abs($rounded - round($rounded)) < 0.0000001) {
            return ((string) (int) round($rounded)).'٪';
        }

        return rtrim(rtrim(number_format($rounded, 2, '.', ''), '0'), '.').'٪';
    }

    private function monthlyAttributedProfit(int $userId, int $roleId, CarbonInterface $from, CarbonInterface $to): string
    {
        $rows = Commission::query()
            ->where('user_id', $userId)
            ->where('role_id', $roleId)
            ->where('status', 'posted')
            ->where('created_at', '>=', $from)
            ->where('created_at', '<=', $to)
            ->where('idempotency_key', 'not like', 'monthly-bonus:%')
            ->get(['base_amount', 'metadata']);

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
                // leave as posted if clawback fails
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
