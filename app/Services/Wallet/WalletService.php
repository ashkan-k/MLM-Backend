<?php

namespace App\Services\Wallet;

use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class WalletService
{
    public function walletFor(User $user, Role $role, string $currency = 'IRT'): Wallet
    {
        return Wallet::query()->firstOrCreate(
            [
                'user_id' => $user->id,
                'role_id' => $role->id,
                'currency' => $currency,
            ],
            [
                'balance' => '0.000',
                'held_balance' => '0.000',
                'is_active' => true,
            ]
        );
    }

    public function credit(Wallet $wallet, string $amount, string $type, string $idempotencyKey, ?string $referenceType = null, ?int $referenceId = null, array $metadata = []): WalletTransaction
    {
        return $this->post($wallet, $amount, $type, $idempotencyKey, $referenceType, $referenceId, $metadata, false);
    }

    public function debit(Wallet $wallet, string $amount, string $type, string $idempotencyKey, ?string $referenceType = null, ?int $referenceId = null, array $metadata = []): WalletTransaction
    {
        return $this->post($wallet, $amount, $type, $idempotencyKey, $referenceType, $referenceId, $metadata, true);
    }

    public function hold(Wallet $wallet, string $amount, string $idempotencyKey, ?string $referenceType = null, ?int $referenceId = null): WalletTransaction
    {
        return DB::transaction(function () use ($wallet, $amount, $idempotencyKey, $referenceType, $referenceId) {
            if ($existing = WalletTransaction::query()->where('idempotency_key', $idempotencyKey)->first()) {
                return $existing;
            }

            $locked = Wallet::query()->whereKey($wallet->id)->lockForUpdate()->firstOrFail();
            $amount = Money::normalize($amount);
            if (Money::cmp($locked->availableBalance(), $amount) < 0) {
                throw new RuntimeException('موجودی کافی نیست.');
            }

            $before = Money::normalize((string) $locked->held_balance);
            $locked->held_balance = Money::add($before, $amount);
            $locked->save();

            return WalletTransaction::query()->create([
                'wallet_id' => $locked->id,
                'type' => 'withdrawal_hold',
                'amount' => $amount,
                'balance_before' => $locked->balance,
                'balance_after' => $locked->balance,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'idempotency_key' => $idempotencyKey,
                'metadata' => ['held_before' => $before, 'held_after' => $locked->held_balance],
            ]);
        });
    }

    public function releaseHold(Wallet $wallet, string $amount, string $idempotencyKey, ?string $referenceType = null, ?int $referenceId = null): WalletTransaction
    {
        return DB::transaction(function () use ($wallet, $amount, $idempotencyKey, $referenceType, $referenceId) {
            if ($existing = WalletTransaction::query()->where('idempotency_key', $idempotencyKey)->first()) {
                return $existing;
            }

            $locked = Wallet::query()->whereKey($wallet->id)->lockForUpdate()->firstOrFail();
            $amount = Money::normalize($amount);
            $beforeHeld = Money::normalize((string) $locked->held_balance);
            $locked->held_balance = Money::sub($beforeHeld, $amount);
            $locked->save();

            return WalletTransaction::query()->create([
                'wallet_id' => $locked->id,
                'type' => 'withdrawal_release',
                'amount' => $amount,
                'balance_before' => $locked->balance,
                'balance_after' => $locked->balance,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'idempotency_key' => $idempotencyKey,
                'metadata' => ['held_before' => $beforeHeld, 'held_after' => $locked->held_balance],
            ]);
        });
    }

    public function captureHold(Wallet $wallet, string $amount, string $idempotencyKey, ?string $referenceType = null, ?int $referenceId = null): WalletTransaction
    {
        return DB::transaction(function () use ($wallet, $amount, $idempotencyKey, $referenceType, $referenceId) {
            if ($existing = WalletTransaction::query()->where('idempotency_key', $idempotencyKey)->first()) {
                return $existing;
            }

            $locked = Wallet::query()->whereKey($wallet->id)->lockForUpdate()->firstOrFail();
            $amount = Money::normalize($amount);
            $before = Money::normalize((string) $locked->balance);
            $beforeHeld = Money::normalize((string) $locked->held_balance);
            $locked->balance = Money::sub($before, $amount);
            $locked->held_balance = Money::sub($beforeHeld, $amount);
            $locked->save();

            return WalletTransaction::query()->create([
                'wallet_id' => $locked->id,
                'type' => 'withdrawal_debit',
                'amount' => $amount,
                'balance_before' => $before,
                'balance_after' => $locked->balance,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'idempotency_key' => $idempotencyKey,
                'metadata' => ['held_before' => $beforeHeld, 'held_after' => $locked->held_balance],
            ]);
        });
    }

    private function post(Wallet $wallet, string $amount, string $type, string $idempotencyKey, ?string $referenceType, ?int $referenceId, array $metadata, bool $debit): WalletTransaction
    {
        return DB::transaction(function () use ($wallet, $amount, $type, $idempotencyKey, $referenceType, $referenceId, $metadata, $debit) {
            if ($existing = WalletTransaction::query()->where('idempotency_key', $idempotencyKey)->first()) {
                return $existing;
            }

            $locked = Wallet::query()->whereKey($wallet->id)->lockForUpdate()->firstOrFail();
            $amount = Money::normalize($amount);
            $before = Money::normalize((string) $locked->balance);
            $after = $debit ? Money::sub($before, $amount) : Money::add($before, $amount);

            if ($debit && Money::cmp($locked->availableBalance(), $amount) < 0) {
                throw new RuntimeException('موجودی کافی نیست.');
            }

            $locked->balance = $after;
            $locked->save();

            return WalletTransaction::query()->create([
                'wallet_id' => $locked->id,
                'type' => $type,
                'amount' => $amount,
                'balance_before' => $before,
                'balance_after' => $after,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'idempotency_key' => $idempotencyKey,
                'metadata' => $metadata,
            ]);
        });
    }
}
