<?php

namespace App\Services\Commission;

use App\Models\GatewayBonusEligibility;
use App\Models\GatewayManager;
use App\Models\GatewayRepresentative;
use App\Models\GatewaySale;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\UserPointAdjustment;
use App\Services\Organization\OrganizationTreeService;
use Carbon\CarbonInterface;

/**
 * Monthly qualification by registration points (100 per successful gateway).
 * When the month threshold is met, that month's counted gateways become
 * permanently bonus-eligible for the user/role.
 */
class QualificationService
{
    public function __construct(
        private readonly OrganizationTreeService $tree,
    ) {}

    public function pointsPerGateway(): int
    {
        return (int) config('finopal.full_sale_points', 100);
    }

    /** Distinct successful gateway registrations counting for this role this month. */
    public function monthGatewayIds(string $ruleCode, User $user, int $roleId, CarbonInterface $at): array
    {
        $start = $at->copy()->startOfMonth();
        $end = $at->copy()->endOfMonth()->min($at);

        return match ($ruleCode) {
            'representative' => $this->representativeGatewayIds($user, $start, $end),
            'sales_manager', 'development_manager' => $this->managerGatewayIds($user, $roleId, $ruleCode, $start, $end),
            default => [],
        };
    }

    public function gatewayPoints(string $ruleCode, User $user, int $roleId, CarbonInterface $at): int
    {
        return count($this->monthGatewayIds($ruleCode, $user, $roleId, $at)) * $this->pointsPerGateway();
    }

    public function manualPoints(User $user, int $roleId, CarbonInterface $at): int
    {
        $key = $at->copy()->startOfMonth()->format('Y-m');

        return (int) UserPointAdjustment::query()
            ->where('user_id', $user->id)
            ->where('role_id', $roleId)
            ->where('month_key', $key)
            ->sum('delta_points');
    }

    public function monthPoints(string $ruleCode, User $user, int $roleId, CarbonInterface $at): int
    {
        return $this->gatewayPoints($ruleCode, $user, $roleId, $at) + $this->manualPoints($user, $roleId, $at);
    }

    public function requiredPoints(string $ruleCode): int
    {
        $t = $this->thresholds();

        return match ($ruleCode) {
            'representative' => $t['representative_points'],
            'sales_manager' => $t['sales_manager_points'],
            'development_manager' => $t['development_manager_points'],
            default => 0,
        };
    }

    public function isQualified(string $ruleCode, User $user, int $roleId, CarbonInterface $at): bool
    {
        $required = $this->requiredPoints($ruleCode);
        if ($required <= 0) {
            return false;
        }

        return $this->monthPoints($ruleCode, $user, $roleId, $at) >= $required
            || $this->hasPermanentEligibilities($user->id, $roleId);
    }

    /**
     * True if user already has any permanently eligible gateways (keeps earning on those forever).
     * Month threshold itself still gates marking NEW gateways this month.
     */
    public function monthThresholdMet(string $ruleCode, User $user, int $roleId, CarbonInterface $at): bool
    {
        $required = $this->requiredPoints($ruleCode);
        if ($required <= 0) {
            return false;
        }

        return $this->monthPoints($ruleCode, $user, $roleId, $at) >= $required;
    }

    public function hasPermanentEligibilities(int $userId, int $roleId): bool
    {
        return GatewayBonusEligibility::query()
            ->where('user_id', $userId)
            ->where('role_id', $roleId)
            ->exists();
    }

    /**
     * When this month's points hit the threshold, permanently mark this month's counted gateways.
     *
     * @return int number of newly marked gateways
     */
    public function syncPermanentEligibilities(string $ruleCode, User $user, int $roleId, CarbonInterface $at): int
    {
        if (! $this->monthThresholdMet($ruleCode, $user, $roleId, $at)) {
            return 0;
        }

        $monthKey = $at->copy()->startOfMonth()->format('Y-m');
        $ids = $this->monthGatewayIds($ruleCode, $user, $roleId, $at);
        $created = 0;
        foreach ($ids as $saleId) {
            $row = GatewayBonusEligibility::query()->firstOrCreate(
                [
                    'user_id' => $user->id,
                    'role_id' => $roleId,
                    'gateway_sale_id' => $saleId,
                ],
                ['qualified_month' => $monthKey]
            );
            if ($row->wasRecentlyCreated) {
                $created++;
            }
        }

        return $created;
    }

    /** @return list<int> */
    public function permanentEligibleSaleIds(int $userId, int $roleId): array
    {
        return GatewayBonusEligibility::query()
            ->where('user_id', $userId)
            ->where('role_id', $roleId)
            ->pluck('gateway_sale_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function progress(string $ruleCode, User $user, int $roleId, CarbonInterface $at): array
    {
        $required = $this->requiredPoints($ruleCode);
        if ($required <= 0) {
            return [
                'actual' => 0,
                'required' => 0,
                'metric' => null,
                'metric_label' => null,
                'unit' => null,
                'points_per_full_sale' => $this->pointsPerGateway(),
                'guide' => [],
            ];
        }

        $gatewayPoints = $this->gatewayPoints($ruleCode, $user, $roleId, $at);
        $manualPoints = $this->manualPoints($user, $roleId, $at);
        $actual = $gatewayPoints + $manualPoints;
        $gateways = count($this->monthGatewayIds($ruleCode, $user, $roleId, $at));
        $needGateways = (int) ceil($required / max(1, $this->pointsPerGateway()));
        $remaining = max(0, $required - $actual);
        $fmt = static fn (int|float $n): string => number_format((float) $n, 0, '.', ',');
        $ppg = $this->pointsPerGateway();
        $roleTitle = match ($ruleCode) {
            'representative' => 'نماینده',
            'sales_manager' => 'مدیر فروش',
            'development_manager' => 'مدیر توسعه',
            default => $ruleCode,
        };

        $scope = $ruleCode === 'representative'
            ? 'فقط درگاه‌های ثبت‌شده خودتان'
            : 'درگاه‌های خودتان و زیرمجموعه‌تان در این سمت';

        $manualNote = $manualPoints !== 0
            ? " (شامل {$fmt($manualPoints)} امتیاز دستی سوپریوزر)"
            : '';

        $guide = [
            "حد نصاب پاداش این ماه برای نقش {$roleTitle}: {$fmt($required)} امتیاز (هر درگاه موفق = {$fmt($ppg)} امتیاز؛ معادل حدود {$fmt($needGateways)} درگاه).",
            "وضعیت فعلی شما در ماه جاری: {$fmt($actual)} از {$fmt($required)} امتیاز ({$fmt($gateways)} درگاه ثبت‌نامی + دستی){$manualNote}"
                .($remaining > 0
                    ? "؛ هنوز {$fmt($remaining)} امتیاز باقی مانده است."
                    : ' — حد نصاب این ماه تکمیل شده است.'),
            "محدوده شمارش: {$scope}. با شروع ماه بعد، شمارش امتیاز ثبت درگاه از صفر آغاز می‌شود.",
            'اگر تا پایان ماه به حد نصاب نرسید، درگاه‌های همان ماه برای پاداش دائمی قفل نمی‌شوند.',
            'پس از رسیدن به حد نصاب، همان درگاه‌های ثبت‌شدهٔ این ماه برای همیشه واجد شرایط پاداش می‌مانند و هر تراکنش بعدی روی آن‌ها پاداش این نقش را می‌سازد.',
        ];

        return [
            'actual' => $actual,
            'required' => $required,
            'metric' => 'points',
            'metric_label' => 'حداقل امتیاز ثبت درگاه ماهانه',
            'unit' => 'امتیاز',
            'points_per_full_sale' => $ppg,
            'gateway_count' => $gateways,
            'gateway_points' => $gatewayPoints,
            'manual_points' => $manualPoints,
            'guide' => $guide,
        ];
    }

    /** @return array{representative_points: int, sales_manager_points: int, development_manager_points: int} */
    public function thresholds(): array
    {
        $raw = SystemSetting::getValue('qualification_thresholds', [
            'representative_points' => 1000,
            'sales_manager_points' => 5000,
            'development_manager_points' => 20000,
        ]);
        $ppg = $this->pointsPerGateway();

        // سازگاری با کلیدهای قدیمی مبتنی بر تعداد درگاه
        $rep = (int) ($raw['representative_points']
            ?? (isset($raw['representative_gateways']) ? ((int) $raw['representative_gateways'] * $ppg) : 1000));
        $sm = (int) ($raw['sales_manager_points']
            ?? (isset($raw['sales_manager_gateways']) ? ((int) $raw['sales_manager_gateways'] * $ppg) : 5000));
        $dm = (int) ($raw['development_manager_points']
            ?? (isset($raw['development_manager_gateways']) ? ((int) $raw['development_manager_gateways'] * $ppg) : 20000));

        return [
            'representative_points' => $rep,
            'sales_manager_points' => $sm,
            'development_manager_points' => $dm,
        ];
    }

    /** @return list<int> */
    private function representativeGatewayIds(User $user, CarbonInterface $from, CarbonInterface $to): array
    {
        return GatewayRepresentative::query()
            ->where('user_id', $user->id)
            ->whereHas('sale', function ($q) use ($from, $to) {
                $q->where('status', 'successful')
                    ->whereBetween('sold_at', [$from, $to]);
            })
            ->distinct()
            ->pluck('gateway_sale_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Manager: gateways where they are on the manager chain, plus gateways registered by downline
     * (covered by manager attachment in practice; also union downline rep sales).
     *
     * @return list<int>
     */
    private function managerGatewayIds(
        User $user,
        int $roleId,
        string $roleSlug,
        CarbonInterface $from,
        CarbonInterface $to,
    ): array {
        $asManager = GatewayManager::query()
            ->where('user_id', $user->id)
            ->where('role_id', $roleId)
            ->whereHas('sale', function ($q) use ($from, $to) {
                $q->where('status', 'successful')
                    ->whereBetween('sold_at', [$from, $to]);
            })
            ->distinct()
            ->pluck('gateway_sale_id')
            ->map(fn ($id) => (int) $id);

        $downlineIds = $this->tree->descendantUserIds($user, $roleSlug);
        $fromDownline = collect();
        if ($downlineIds->isNotEmpty()) {
            $fromDownline = GatewayRepresentative::query()
                ->whereIn('user_id', $downlineIds->all())
                ->whereHas('sale', function ($q) use ($from, $to) {
                    $q->where('status', 'successful')
                        ->whereBetween('sold_at', [$from, $to]);
                })
                ->distinct()
                ->pluck('gateway_sale_id')
                ->map(fn ($id) => (int) $id);
        }

        // درگاه‌های خود مدیر اگر به‌عنوان نماینده هم ثبت کرده باشد
        $asRep = collect($this->representativeGatewayIds($user, $from, $to));

        return $asManager->merge($fromDownline)->merge($asRep)->unique()->values()->all();
    }
}
