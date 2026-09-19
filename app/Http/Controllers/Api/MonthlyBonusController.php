<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Commission;
use App\Services\Commission\MonthlyBonusService;
use Illuminate\Http\Request;

class MonthlyBonusController extends Controller
{
    public function show(Request $request, MonthlyBonusService $bonuses)
    {
        $user = $request->user();
        $role = $request->attributes->get('active_role');

        if (! $role) {
            return response()->json([
                'role' => null,
                'current' => null,
                'history' => [],
                'monitor' => null,
            ]);
        }

        $current = $bonuses->preview($user, $role->slug, now());
        $isSeniorMonitor = $role->slug === 'senior_manager';

        $history = [];
        if (! $isSeniorMonitor) {
            $history = Commission::query()
                ->where('user_id', $user->id)
                ->where('role_id', $role->id)
                ->where('idempotency_key', 'like', 'monthly-bonus:%')
                ->latest('id')
                ->limit(36)
                ->get()
                ->map(function (Commission $row) {
                    $meta = $row->metadata ?? [];

                    return [
                        'id' => $row->id,
                        'month' => $meta['month'] ?? ($row->created_at?->format('Y-m')),
                        'status' => $row->status,
                        'bonus_percent' => (string) $row->commission_percent,
                        'profit_sum' => (string) ($meta['profit_sum'] ?? $row->base_amount),
                        'bonus_amount' => (string) $row->commission_amount,
                        'qualified' => (bool) ($meta['qualified'] ?? ($row->status === 'posted')),
                        'created_at' => $row->created_at?->toIso8601String(),
                        'metadata' => $meta,
                    ];
                })
                ->values();
        }

        $monitor = null;
        if ($isSeniorMonitor) {
            $data = $request->validate([
                'page' => ['nullable', 'integer', 'min:1'],
                'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
                'search' => ['nullable', 'string', 'max:80'],
                'role_slug' => ['nullable', 'string', 'max:64'],
            ]);
            $monitor = $bonuses->downlineMonitor(
                $user,
                (int) ($data['page'] ?? 1),
                (int) ($data['per_page'] ?? 20),
                $data['search'] ?? null,
                $data['role_slug'] ?? null,
            );
        }

        return response()->json([
            'role' => $role,
            'current' => $current,
            'history' => $history,
            'monitor' => $monitor,
        ]);
    }

    public function pay(Request $request, MonthlyBonusService $bonuses)
    {
        $actor = $request->user();
        $active = $request->attributes->get('active_role');
        if (! $actor->isSuperuser() && (! $active || $active->slug !== 'senior_manager')) {
            return response()->json(['message' => 'فقط مدیر ارشد می‌تواند پاداش را دستی واریز کند.'], 403);
        }

        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'role_slug' => ['required', 'string', 'in:representative,sales_manager,development_manager'],
        ]);

        $target = \App\Models\User::query()->findOrFail($data['user_id']);
        $commission = $bonuses->payFor($actor, $target, $data['role_slug'], now());

        return response()->json([
            'message' => 'پاداش ماهانه واریز شد.',
            'commission' => [
                'id' => $commission->id,
                'amount' => (string) $commission->commission_amount,
                'percent' => (string) $commission->commission_percent,
                'status' => $commission->status,
            ],
        ]);
    }
}
