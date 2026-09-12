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
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function show(Request $request, QualificationService $qualification, OrganizationTreeService $tree)
    {
        $user = $request->user();
        $role = $request->attributes->get('active_role');

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
                ->where('sold_at', '>=', now()->startOfMonth())
                ->count(),
            'pending_promotions' => PromotionRequest::query()->where('status', 'pending')->count(),
            'pending_withdrawals' => WithdrawalRequest::query()
                ->whereIn('status', ['senior_manager_pending', 'superuser_pending'])
                ->count(),
        ]);
    }
}
