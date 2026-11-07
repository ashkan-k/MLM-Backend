<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CommissionRule;
use App\Services\Commission\CommissionRuleResolver;
use App\Services\Commission\QualificationService;
use Carbon\Carbon;

class PublicCommissionController extends Controller
{
    public function rates(CommissionRuleResolver $resolver, QualificationService $qualification)
    {
        $at = Carbon::now();
        $thresholds = $qualification->thresholds();
        $ppg = $qualification->pointsPerGateway();

        $thresholdByCode = [
            'representative' => (int) ($thresholds['representative_points'] ?? 1000),
            'sales_manager' => (int) ($thresholds['sales_manager_points'] ?? 5000),
            'development_manager' => (int) ($thresholds['development_manager_points'] ?? 20000),
        ];

        $order = [
            'representative',
            'representative_referrer',
            'sales_manager',
            'development_manager',
            'senior_manager',
        ];

        $rules = CommissionRule::query()
            ->where('is_active', true)
            ->whereIn('code', $order)
            ->get()
            ->keyBy('code');

        $out = [];
        foreach ($order as $code) {
            $rule = $rules->get($code);
            if (! $rule) {
                continue;
            }
            $version = $resolver->resolve($code, $at);
            $base = round((float) ($version?->percent ?? $rule->default_percent), 1);
            $qualified = round((float) ($version?->qualified_percent ?? $version?->percent ?? $rule->default_percent), 1);
            if ($qualified < $base) {
                $qualified = $base;
            }
            $bonus = round($qualified - $base, 1);
            $out[] = [
                'slug' => $code,
                'label' => $rule->name,
                'base_percent' => $base,
                'qualified_percent' => $qualified,
                'bonus_delta_percent' => $bonus,
                'points_threshold' => $thresholdByCode[$code] ?? null,
                'points_per_gateway' => $ppg,
                'qualification_type' => $rule->qualification_type,
            ];
        }

        return response()->json([
            'roles' => $out,
            'points_per_gateway' => $ppg,
        ]);
    }
}
