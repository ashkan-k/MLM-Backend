<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Wallet;
use App\Models\WithdrawalRequest;
use App\Services\Withdrawal\WithdrawalService;
use Illuminate\Http\Request;
use RuntimeException;

class WithdrawalController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $query = WithdrawalRequest::query()->with(['wallet.role', 'user', 'approvals']);

        if (! $user->isSuperuser() && ! $user->hasRole('senior_manager')) {
            $query->where('user_id', $user->id);
        }

        return response()->json($query->latest()->paginate(20));
    }

    public function store(Request $request, WithdrawalService $service)
    {
        $data = $request->validate([
            'scope' => ['nullable', 'in:active_role,all_roles'],
            'wallet_id' => ['nullable', 'exists:wallets,id'],
            'amount' => ['required', 'numeric', 'min:0.001'],
            'idempotency_key' => ['nullable', 'string'],
        ]);

        $scope = $data['scope'] ?? 'active_role';
        $wallet = ! empty($data['wallet_id']) ? Wallet::query()->findOrFail($data['wallet_id']) : null;
        $activeRole = $request->attributes->get('active_role');

        try {
            return response()->json(
                $service->request(
                    $request->user(),
                    (string) $data['amount'],
                    $scope,
                    $wallet,
                    $activeRole,
                    $data['idempotency_key'] ?? null,
                ),
                201
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function decide(Request $request, WithdrawalRequest $withdrawal, WithdrawalService $service)
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'note' => ['nullable', 'string'],
        ]);

        try {
            return response()->json($service->decide($request->user(), $withdrawal, $data['decision'], $data['note'] ?? ''));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function cancel(Request $request, WithdrawalRequest $withdrawal, WithdrawalService $service)
    {
        return response()->json($service->cancel($request->user(), $withdrawal));
    }
}
