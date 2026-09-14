<?php

namespace App\Services\Commission;

use App\Models\Commission;
use App\Models\GatewaySale;
use App\Models\Role;
use App\Models\User;
use App\Services\Wallet\WalletService;
use App\Support\Money;

class CommissionLedger
{
    public function __construct(private readonly WalletService $wallets) {}

    public function post(
        User $user,
        Role $role,
        GatewaySale $sale,
        ?int $ruleVersionId,
        string $baseAmount,
        string $percent,
        string $amount,
        string $idempotencyKey,
        array $metadata = [],
        ?int $transactionId = null,
    ): Commission {
        $existing = Commission::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return $existing;
        }

        $commission = Commission::query()->create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'gateway_sale_id' => $sale->id,
            'finopal_transaction_id' => $transactionId,
            'rule_version_id' => $ruleVersionId,
            'base_amount' => Money::normalize($baseAmount, 3),
            'commission_percent' => Money::normalize($percent, 3),
            'commission_amount' => Money::normalize($amount, 3),
            'status' => 'posted',
            'idempotency_key' => $idempotencyKey,
            'metadata' => $metadata,
        ]);

        $wallet = $this->wallets->walletFor($user, $role);
        $this->wallets->credit(
            $wallet,
            (string) $commission->commission_amount,
            'commission_credit',
            'wallet-'.$idempotencyKey,
            Commission::class,
            $commission->id
        );

        return $commission;
    }
}
