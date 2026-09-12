<?php

namespace App\Services\Commission;

use App\Models\GatewayManager;
use App\Models\GatewayRepresentative;
use App\Models\GatewaySale;
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

        return GatewayManager::query()
            ->where('user_id', $user->id)
            ->where('role_id', $roleId)
            ->whereHas('sale', function ($q) use ($start, $at) {
                $q->where('status', 'successful')
                    ->whereBetween('sold_at', [$start, $at]);
            })
            ->count();
    }

    public function isQualified(string $ruleCode, User $user, int $roleId, CarbonInterface $at): bool
    {
        $thresholds = SystemSetting::getValue('qualification_thresholds', [
            'representative_points' => 1000,
            'sales_manager_gateways' => 50,
            'development_manager_gateways' => 200,
        ]);

        return match ($ruleCode) {
            'representative' => (float) $this->representativePoints($user, $at) >= (float) $thresholds['representative_points'],
            'sales_manager' => $this->managerGateways($user, $roleId, $at) >= (int) $thresholds['sales_manager_gateways'],
            'development_manager' => $this->managerGateways($user, $roleId, $at) >= (int) $thresholds['development_manager_gateways'],
            default => false,
        };
    }

    public function progress(string $ruleCode, User $user, int $roleId, CarbonInterface $at): array
    {
        $thresholds = SystemSetting::getValue('qualification_thresholds', [
            'representative_points' => 1000,
            'sales_manager_gateways' => 50,
            'development_manager_gateways' => 200,
        ]);

        return match ($ruleCode) {
            'representative' => [
                'actual' => (float) $this->representativePoints($user, $at),
                'required' => (float) $thresholds['representative_points'],
            ],
            'sales_manager' => [
                'actual' => $this->managerGateways($user, $roleId, $at),
                'required' => (int) $thresholds['sales_manager_gateways'],
            ],
            'development_manager' => [
                'actual' => $this->managerGateways($user, $roleId, $at),
                'required' => (int) $thresholds['development_manager_gateways'],
            ],
            default => ['actual' => 0, 'required' => 0],
        };
    }
}
