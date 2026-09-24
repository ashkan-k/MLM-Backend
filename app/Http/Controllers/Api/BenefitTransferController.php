<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BenefitTransfer;
use App\Models\GatewayRepresentative;
use App\Models\User;
use App\Services\Authorization\PermissionService;
use App\Services\BenefitTransfer\BenefitTransferService;
use Illuminate\Http\Request;

class BenefitTransferController extends Controller
{
    public function index(Request $request)
    {
        $this->assertSeniorManager($request->user());

        return response()->json(BenefitTransfer::query()->with(['fromUser', 'toUser', 'items'])->latest()->get());
    }

    public function store(Request $request, BenefitTransferService $service, PermissionService $permissions)
    {
        $this->assertSeniorManager($request->user());
        $permissions->authorize($request->user(), 'senior_manager.benefit_transfer.create', $request->attributes->get('active_role'));

        $data = $request->validate([
            'from_user_id' => ['required', 'exists:users,id'],
            'to_user_id' => ['required', 'exists:users,id'],
            'type' => ['required', 'in:all_future_benefits,gateway_share'],
            'reason' => ['required', 'string'],
            'gateway_representative_id' => ['nullable', 'exists:gateway_representatives,id'],
            'effective_from' => ['nullable', 'date'],
        ]);

        $from = User::query()->findOrFail($data['from_user_id']);
        $to = User::query()->findOrFail($data['to_user_id']);

        $transfer = $data['type'] === 'all_future_benefits'
            ? $service->transferAll($request->user(), $from, $to, $data['reason'], $data['effective_from'] ?? null)
            : $service->transferShare($request->user(), $from, $to, (int) $data['gateway_representative_id'], $data['reason'], $data['effective_from'] ?? null);

        return response()->json($transfer, 201);
    }

    public function shares(Request $request, User $user)
    {
        $this->assertSeniorManager($request->user());

        return response()->json(
            GatewayRepresentative::query()->with('sale.gateway')->where('user_id', $user->id)->get()
        );
    }

    private function assertSeniorManager(?User $user): void
    {
        if (! $user?->isSuperuser() && ! $user?->hasRole('senior_manager')) {
            abort(403, 'فقط مدیر ارشد می‌تواند انتقال مالکیت مزایا را انجام دهد.');
        }
    }
}
