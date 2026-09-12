<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Commission;
use App\Models\Gateway;
use App\Models\GatewaySale;
use App\Services\Authorization\PermissionService;
use App\Services\Gateway\GatewaySaleService;
use Illuminate\Http\Request;

class GatewayController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $query = Gateway::query()->with(['sales.representatives']);

        if (! $user->isSuperuser() && ! $user->hasRole('senior_manager')) {
            $query->whereHas('sales.representatives', fn ($q) => $q->where('user_id', $user->id));
        }

        return response()->json($query->latest()->paginate(20));
    }

    public function sales(Request $request)
    {
        $user = $request->user();
        $query = GatewaySale::query()->with(['gateway', 'representatives.user', 'referrers.user', 'managers.user']);

        if (! $user->isSuperuser() && ! $user->hasRole('senior_manager')) {
            $query->where(function ($q) use ($user) {
                $q->whereHas('representatives', fn ($s) => $s->where('user_id', $user->id))
                    ->orWhereHas('referrers', fn ($s) => $s->where('user_id', $user->id))
                    ->orWhereHas('managers', fn ($s) => $s->where('user_id', $user->id));
            });
        }

        return response()->json($query->latest('sold_at')->paginate(20));
    }

    public function store(Request $request, GatewaySaleService $sales, PermissionService $permissions)
    {
        $permissions->authorize($request->user(), 'superuser.gateway.create', $request->attributes->get('active_role'));

        $data = $request->validate([
            'external_id' => ['required', 'string'],
            'name' => ['nullable', 'string'],
            'amount' => ['required', 'numeric', 'min:0'],
            'source' => ['nullable', 'string'],
            'representative_user_id' => ['nullable', 'exists:users,id'],
            'shared_link_id' => ['nullable', 'exists:shared_links,id'],
            'representatives' => ['nullable', 'array'],
            'customer.name' => ['nullable', 'string'],
            'customer.mobile' => ['nullable', 'string'],
            'idempotency_key' => ['nullable', 'string'],
            'sold_at' => ['nullable', 'date'],
        ]);

        return response()->json($sales->record($data), 201);
    }

    public function commissions(Request $request)
    {
        $user = $request->user();
        $role = $request->attributes->get('active_role');

        $query = Commission::query()->with(['role', 'sale.gateway']);
        if (! $user->isSuperuser()) {
            $query->where('user_id', $user->id);
            if ($role) {
                $query->where('role_id', $role->id);
            }
        }

        return response()->json($query->latest()->paginate(20));
    }
}
