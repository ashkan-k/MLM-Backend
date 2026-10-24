<?php

use App\Models\Commission;
use App\Models\Role;
use App\Models\User;
use App\Services\Commission\MonthlyBonusService;
use App\Services\Wallet\WalletService;
use App\Support\JalaliDate;
use App\Support\Money;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Undo today's Jalali monthly-bonus cutover: reverse/remove Jalali-key bonus rows
 * and restore the Gregorian-month bonuses that accounting already booked.
 */
return new class extends Migration
{
    public function up(): void
    {
        $bonuses = app(MonthlyBonusService::class);
        $wallets = app(WalletService::class);

        $monthly = Commission::query()
            ->where(function ($q) {
                $q->where('idempotency_key', 'like', 'monthly-bonus:%')
                    ->orWhere('idempotency_key', 'like', 'monthly-bonus-residual:%');
            })
            ->get();

        foreach ($monthly as $row) {
            if (! preg_match('/:(\d{4}-\d{2})$/', (string) $row->idempotency_key, $m)) {
                continue;
            }
            if (! JalaliDate::isJalaliMonthKey($m[1])) {
                continue;
            }

            if ($row->status === 'posted') {
                $bonuses->reversePosted($row);
                $row->refresh();
                if ($row->status !== 'reversed') {
                    continue;
                }
            }

            $row->delete();
        }

        $reversedGregorian = Commission::query()
            ->where('status', 'reversed')
            ->where(function ($q) {
                $q->where('idempotency_key', 'like', 'monthly-bonus:%')
                    ->orWhere('idempotency_key', 'like', 'monthly-bonus-residual:%');
            })
            ->get();

        foreach ($reversedGregorian as $row) {
            if (! preg_match('/:(\d{4}-\d{2})$/', (string) $row->idempotency_key, $m)) {
                continue;
            }
            // Only the October Gregorian window touched by today's switch.
            if ($m[1] !== '2026-10') {
                continue;
            }

            $meta = $row->metadata ?? [];
            $reversedAt = (string) ($meta['reversed_at'] ?? '');
            if ($reversedAt === '' || ! str_starts_with($reversedAt, '2026-10-24')) {
                continue;
            }

            $amount = Money::normalize((string) $row->commission_amount);
            if (Money::cmp($amount, '0') <= 0) {
                continue;
            }

            DB::transaction(function () use ($row, $wallets, $amount, $meta) {
                $user = User::query()->find($row->user_id);
                $role = Role::query()->find($row->role_id);
                if (! $user || ! $role) {
                    return;
                }

                $isResidual = ($meta['type'] ?? null) === 'monthly_bonus_residual'
                    || str_starts_with((string) $row->idempotency_key, 'monthly-bonus-residual:');
                $wallet = $isResidual
                    ? $wallets->residualBonusWallet($user, $role)
                    : $wallets->walletFor($user, $role);

                $wallets->credit(
                    $wallet,
                    $amount,
                    'monthly_bonus_credit',
                    'wallet-'.$row->idempotency_key.'-unrev-'.$row->id,
                    Commission::class,
                    $row->id,
                    $meta + ['restored_from_jalali_switch' => true]
                );

                unset($meta['reversed_at']);
                $meta['restored_at'] = now()->toIso8601String();
                $row->status = 'posted';
                $row->metadata = $meta;
                $row->save();
            });
        }
    }

    public function down(): void
    {
    }
};
