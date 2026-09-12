<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function show(Request $request)
    {
        $role = $request->attributes->get('active_role');
        $wallet = Wallet::query()
            ->with('role')
            ->where('user_id', $request->user()->id)
            ->when($role && ! $request->user()->isSuperuser(), fn ($q) => $q->where('role_id', $role->id))
            ->get();

        return response()->json($wallet);
    }

    public function transactions(Request $request)
    {
        $role = $request->attributes->get('active_role');
        $walletIds = Wallet::query()
            ->where('user_id', $request->user()->id)
            ->when($role && ! $request->user()->isSuperuser(), fn ($q) => $q->where('role_id', $role->id))
            ->pluck('id');

        return response()->json(
            WalletTransaction::query()
                ->whereIn('wallet_id', $walletIds)
                ->latest()
                ->paginate(20)
        );
    }

    public function aggregate(Request $request)
    {
        $wallets = Wallet::query()
            ->with('role')
            ->where('user_id', $request->user()->id)
            ->get();

        return response()->json([
            'by_role' => $wallets,
            'total_balance' => $wallets->sum(fn ($w) => (float) $w->balance),
            'total_held' => $wallets->sum(fn ($w) => (float) $w->held_balance),
            'note' => 'این گزارش تجمیعی فقط نمایشی است و دفاتر نقش‌ها ادغام نمی‌شوند.',
        ]);
    }
}
