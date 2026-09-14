<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Commission;
use App\Models\GatewaySale;
use App\Models\PromotionRequest;
use App\Models\Wallet;
use App\Models\WithdrawalRequest;
use App\Services\Commission\QualificationService;
use App\Services\Organization\OrganizationTreeService;
use App\Services\Promotion\PromotionService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function show(Request $request, QualificationService $qualification, OrganizationTreeService $tree, PromotionService $promotions)
    {
        $user = $request->user();
        $role = $request->attributes->get('active_role');
        $promotions->autoSubmitIfEligible($user);

        $wallet = $role
            ? Wallet::query()->where('user_id', $user->id)->where('role_id', $role->id)->first()
            : null;

        $progress = $role ? $qualification->progress($role->slug, $user, $role->id, now()) : null;

        return response()->json([
            'role' => $role,
            'wallet' => $wallet,
            'qualification' => $progress,
            'team_count' => $tree->descendants($user)->count(),
            'monthly_commissions' => Commission::query()
                ->where('user_id', $user->id)
                ->when($role, fn ($q) => $q->where('role_id', $role->id))
                ->where('created_at', '>=', now()->startOfMonth())
                ->sum('commission_amount'),
            'monthly_sales' => GatewaySale::query()
                ->whereHas('representatives', fn ($q) => $q->where('user_id', $user->id))
                ->where('status', 'successful')
                ->where('sold_at', '>=', now()->startOfMonth())
                ->count(),
            'pending_gateway_inspections' => GatewaySale::query()->whereIn('status', ['pending_inspection', 'pending_shaparak'])->count(),
            'pending_promotions' => PromotionRequest::query()->where('status', 'pending')->count(),
            'pending_withdrawals' => WithdrawalRequest::query()
                ->whereIn('status', ['senior_manager_pending', 'superuser_pending'])
                ->count(),
            'latest_promotion' => PromotionRequest::query()
                ->with(['targetRole', 'feedback'])
                ->where('user_id', $user->id)
                ->latest()
                ->first(),
            'latest_rejected_promotion' => PromotionRequest::query()
                ->with(['targetRole', 'feedback'])
                ->where('user_id', $user->id)
                ->where('status', 'rejected')
                ->latest('decided_at')
                ->first(),
        ]);
    }
}
