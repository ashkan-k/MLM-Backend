<?php

namespace App\Services\Commission;

use App\Models\GatewayManager;
use App\Models\GatewayRepresentative;
use App\Models\SystemSetting;
use App\Models\User;
use Carbon\CarbonInterface;

class QualificationService
{
    public function representativePoints(User $user, CarbonInterface $at): string
    {
        $start = $at->copy()->startOfMonth();

        return (string) GatewayRepresentative::query()
            ->where('user_id', $user->id)
            ->whereHas('sale', function ($q) use ($start, $at) {
                $q->where('status', 'successful')
                    ->whereBetween('sold_at', [$start, $at]);
            })
            ->sum('sales_points');
    }

    public function managerGateways(User $user, int $roleId, CarbonInterface $at): int
    {
        $start = $at->copy()->startOfMonth();

        // Count distinct gateway registrations attributed to this manager role
        // from the start of the calendar month.
        return (int) GatewayManager::query()
            ->where('user_id', $user->id)
            ->where('role_id', $roleId)
            ->whereHas('sale', function ($q) use ($start, $at) {
                $q->where('status', 'successful')
                    ->whereBetween('sold_at', [$start, $at]);
            })
            ->distinct()
            ->count('gateway_sale_id');
    }

    public function isQualified(string $ruleCode, User $user, int $roleId, CarbonInterface $at): bool
    {
        $thresholds = $this->thresholds();

        return match ($ruleCode) {
            'representative' => (float) $this->representativePoints($user, $at) >= (float) $thresholds['representative_points'],
            'sales_manager' => $this->managerGateways($user, $roleId, $at) >= (int) $thresholds['sales_manager_gateways'],
            'development_manager' => $this->managerGateways($user, $roleId, $at) >= (int) $thresholds['development_manager_gateways'],
            default => false,
        };
    }

    public function progress(string $ruleCode, User $user, int $roleId, CarbonInterface $at): array
    {
        $thresholds = $this->thresholds();
        $pointsPerSale = (int) config('finopal.full_sale_points', 100);

        return match ($ruleCode) {
            'representative' => $this->representativeProgress($user, $at, $thresholds, $pointsPerSale),
            'sales_manager' => $this->managerProgress(
                $user,
                $roleId,
                $at,
                (int) $thresholds['sales_manager_gateways'],
                'مدیر فروش',
            ),
            'development_manager' => $this->managerProgress(
                $user,
                $roleId,
                $at,
                (int) $thresholds['development_manager_gateways'],
                'مدیر توسعه',
            ),
            default => [
                'actual' => 0,
                'required' => 0,
                'metric' => null,
                'metric_label' => null,
                'unit' => null,
                'points_per_full_sale' => null,
                'guide' => [],
            ],
        };
    }

    /** @return array{representative_points: int|float, sales_manager_gateways: int, development_manager_gateways: int} */
    private function thresholds(): array
    {
        return SystemSetting::getValue('qualification_thresholds', [
            'representative_points' => 1000,
            'sales_manager_gateways' => 50,
            'development_manager_gateways' => 200,
        ]);
    }

    private function representativeProgress(User $user, CarbonInterface $at, array $thresholds, int $pointsPerSale): array
    {
        $actual = (float) $this->representativePoints($user, $at);
        $required = (float) $thresholds['representative_points'];
        $remaining = max(0, $required - $actual);
        $fmt = static fn (float|int $n): string => number_format((float) $n, 0, '.', ',');

        $guide = [
            "حد نصاب پاداش این ماه برای نقش نماینده: {$fmt($required)} امتیاز فروش شخصی.",
            "وضعیت فعلی شما: {$fmt($actual)} از {$fmt($required)} امتیاز"
                .($remaining > 0
                    ? "؛ هنوز {$fmt($remaining)} امتیاز تا واجد شرایط شدن باقی مانده است."
                    : ' — حد نصاب تکمیل شده است.'),
            "هر فروش موفق درگاه برابر با {$fmt($pointsPerSale)} امتیاز کامل است. اگر درگاه اشتراکی باشد، امتیاز به نسبت سهم شما محاسبه می‌شود.",
            'فقط فروش‌های موفق از ابتدای ماه جاری تا همین لحظه شمرده می‌شود و با شروع ماه بعد شمارش از صفر آغاز می‌گردد.',
            'پس از رسیدن به حد نصاب، درصد پاداش ماهانه نقش نماینده روی مجموع سود تراکنش‌های همین ماه به کیف پول همین سمت واریز می‌شود.',
        ];

        return [
            'actual' => $actual,
            'required' => $required,
            'metric' => 'points',
            'metric_label' => 'حداقل امتیاز فروش ماهانه',
            'unit' => 'امتیاز',
            'points_per_full_sale' => $pointsPerSale,
            'guide' => $guide,
        ];
    }

    private function managerProgress(User $user, int $roleId, CarbonInterface $at, int $required, string $roleTitle): array
    {
        $actual = $this->managerGateways($user, $roleId, $at);
        $remaining = max(0, $required - $actual);
        $fmt = static fn (int $n): string => number_format($n, 0, '.', ',');

        $guide = [
            "حد نصاب پاداش این ماه برای نقش {$roleTitle}: ثبت حداقل {$fmt($required)} درگاه از ابتدای ماه جاری.",
            "وضعیت فعلی شما: {$fmt($actual)} از {$fmt($required)} درگاه"
                .($remaining > 0
                    ? "؛ هنوز {$fmt($remaining)} درگاه تا واجد شرایط شدن باقی مانده است."
                    : ' — حد نصاب تکمیل شده است.'),
            "منظور درگاه‌هایی است که از اول ماه تا الان در سامانه ثبت و نهایی شده‌اند و شما با سمت {$roleTitle} در زنجیره مدیران همان درگاه هستید.",
            'هر درگاه فقط یک‌بار در ماه شمرده می‌شود؛ با شروع ماه بعد شمارش از صفر آغاز می‌گردد.',
            "پس از رسیدن به حد نصاب، درصد پاداش ماهانه نقش {$roleTitle} روی مجموع سود تراکنش‌های همین ماه به کیف پول همین سمت واریز می‌شود.",
        ];

        return [
            'actual' => $actual,
            'required' => $required,
            'metric' => 'gateways',
            'metric_label' => 'حداقل ثبت درگاه از ابتدای ماه',
            'unit' => 'درگاه',
            'points_per_full_sale' => null,
            'guide' => $guide,
        ];
    }
}
