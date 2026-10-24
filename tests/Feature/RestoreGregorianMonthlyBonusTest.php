<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\Role;
use App\Models\User;
use App\Services\Commission\MonthlyBonusService;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RestoreGregorianMonthlyBonusTest extends TestCase
{
    use RefreshDatabase;

    public function test_restore_migration_puts_october_bonus_back(): void
    {
        $this->seed();

        $user = User::query()->where('mobile', '09121111111')->firstOrFail();
        $role = Role::query()->where('slug', 'development_manager')->firstOrFail();
        $wallets = app(WalletService::class);
        $wallet = $wallets->walletFor($user, $role);
        $baseline = (float) $wallet->fresh()->balance;

        $oct = Commission::query()->create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'gateway_sale_id' => null,
            'finopal_transaction_id' => null,
            'rule_version_id' => null,
            'base_amount' => '1840800.000',
            'commission_percent' => '1.500',
            'commission_amount' => '27612.000',
            'status' => 'posted',
            'idempotency_key' => "monthly-bonus:{$user->id}:{$role->id}:2026-10",
            'metadata' => [
                'type' => 'monthly_bonus',
                'month' => '2026-10',
                'profit_sum' => '1840800.000',
            ],
        ]);
        $wallets->credit($wallet, '27612.000', 'monthly_bonus_credit', 'wallet-'.$oct->idempotency_key, Commission::class, $oct->id);

        app(MonthlyBonusService::class)->reversePosted($oct);
        $oct->refresh();
        $meta = $oct->metadata ?? [];
        $meta['reversed_at'] = '2026-10-24T05:30:00+00:00';
        $oct->metadata = $meta;
        $oct->save();

        $mehr = Commission::query()->create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'gateway_sale_id' => null,
            'finopal_transaction_id' => null,
            'rule_version_id' => null,
            'base_amount' => '1834800.000',
            'commission_percent' => '1.500',
            'commission_amount' => '27522.000',
            'status' => 'posted',
            'idempotency_key' => "monthly-bonus:{$user->id}:{$role->id}:1405-07",
            'metadata' => ['type' => 'monthly_bonus', 'month' => '1405-07'],
        ]);
        $wallets->credit($wallet, '27522.000', 'monthly_bonus_credit', 'wallet-'.$mehr->idempotency_key, Commission::class, $mehr->id);

        $aban = Commission::query()->create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'gateway_sale_id' => null,
            'finopal_transaction_id' => null,
            'rule_version_id' => null,
            'base_amount' => '6000.000',
            'commission_percent' => '1.500',
            'commission_amount' => '90.000',
            'status' => 'posted',
            'idempotency_key' => "monthly-bonus:{$user->id}:{$role->id}:1405-08",
            'metadata' => ['type' => 'monthly_bonus', 'month' => '1405-08'],
        ]);
        $wallets->credit($wallet, '90.000', 'monthly_bonus_credit', 'wallet-'.$aban->idempotency_key, Commission::class, $aban->id);

        // After reverse(oct) + credit(mehr+aban): net same as a single posted October bonus.
        $this->assertEqualsWithDelta($baseline + 27612.0, (float) $wallet->fresh()->balance, 0.01);

        $migration = require database_path('migrations/2026_10_24_000200_restore_gregorian_monthly_bonuses.php');
        $migration->up();

        $this->assertDatabaseMissing('commissions', ['idempotency_key' => $mehr->idempotency_key]);
        $this->assertDatabaseMissing('commissions', ['idempotency_key' => $aban->idempotency_key]);

        $oct->refresh();
        $this->assertSame('posted', $oct->status);
        $this->assertEqualsWithDelta(27612.0, (float) $oct->commission_amount, 0.01);
        $this->assertArrayNotHasKey('reversed_at', $oct->metadata ?? []);

        $this->assertEqualsWithDelta($baseline + 27612.0, (float) $wallet->fresh()->balance, 0.01);
    }
}
