<?php

namespace App\Console\Commands;

use App\Models\Commission;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Wallet\WalletService;
use App\Support\Money;
use Illuminate\Console\Command;

class SeedResidualWalletDemoCommand extends Command
{
    protected $signature = 'finopal:seed-residual-wallet-demo {--force : واریز نمونه حتی اگر قبلاً مانده دارد}';

    protected $description = 'واریز نمونه به کیف جداگانهٔ پاداش اضافه مدیر ارشد برای تست بصری';

    public function handle(WalletService $wallets): int
    {
        $role = Role::query()->where('slug', 'senior_manager')->first();
        $senior = User::query()
            ->whereHas('roles', fn ($q) => $q->where('slug', 'senior_manager'))
            ->orderBy('id')
            ->first();

        if (! $role || ! $senior) {
            $this->error('مدیر ارشد پیدا نشد.');

            return self::FAILURE;
        }

        $personal = $wallets->walletFor($senior, $role);
        $residual = $wallets->residualBonusWallet($senior, $role);

        if (Money::cmp((string) $personal->balance, '0') <= 0) {
            $wallets->credit(
                $personal,
                '42000.000',
                'commission_credit',
                'lab-visual-personal-share',
                null,
                null,
                ['type' => 'visual_demo', 'note' => 'نمونه سهم شخصی مدیر ارشد']
            );
            $this->info('نمونه سهم شخصی: ۴۲٬۰۰۰ تومان در کیف نقش.');
        }

        $residual->refresh();
        $amount = '185000.000';
        $key = 'lab-visual-residual-bonus';
        $commission = Commission::query()->where('idempotency_key', $key)->first();
        $walletKey = 'wallet-'.$key;

        if ($this->option('force') || Money::cmp((string) $residual->balance, '0') <= 0) {
            if (! $commission) {
                $commission = Commission::query()->create([
                    'user_id' => $senior->id,
                    'role_id' => $role->id,
                    'base_amount' => $amount,
                    'commission_percent' => '0.000',
                    'commission_amount' => $amount,
                    'status' => 'posted',
                    'idempotency_key' => $key,
                    'metadata' => [
                        'type' => 'monthly_bonus_residual',
                        'month' => now()->format('Y-m'),
                        'note' => 'نمونه پاداش اضافه برای تست بصری',
                    ],
                ]);
            }

            // اگر رکورد پورسانت هست ولی کیف خالی است (پس از ریست ناقص)، دوباره واریز کن
            $wallets->credit(
                $residual,
                Money::normalize((string) $commission->commission_amount, 3),
                'monthly_bonus_residual',
                $walletKey,
                Commission::class,
                $commission->id,
                ['type' => 'monthly_bonus_residual', 'visual_demo' => true]
            );
            $this->info('نمونه پاداش اضافه در کیف جدا همگام شد: '.$commission->commission_amount.' تومان.');
        } else {
            $this->comment('کیف پاداش اضافه از قبل مانده دارد؛ نمونه جدید واریز نشد.');
        }

        $personal->refresh();
        $residual->refresh();
        $this->table(['کیف', 'kind', 'مانده'], [
            ['سهم شخصی / پورسانت', Wallet::KIND_ROLE, $personal->balance],
            ['پاداش‌های اضافه', Wallet::KIND_BONUS_RESIDUAL, $residual->balance],
        ]);
        $this->comment('ورود: مدیر ارشد → کیف پول. دو کارت جدا باید دیده شود.');

        return self::SUCCESS;
    }
}
