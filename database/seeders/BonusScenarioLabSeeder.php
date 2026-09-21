<?php

namespace Database\Seeders;

use App\Models\Gateway;
use App\Models\GatewayManager;
use App\Models\GatewayRepresentative;
use App\Models\GatewaySale;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Commission\QualificationService;
use App\Services\Integration\Finopal\FinopalTransactionService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Visual lab: monthly points + permanent gateway eligibility + residual to senior.
 * Assumes FullDemoOrgSeeder (or finopal:seed-demo-users) already ran.
 */
class BonusScenarioLabSeeder extends Seeder
{
    public function run(): void
    {
        // آستانه‌های پایین برای تست بصری سریع (۱ درگاه = ۱۰۰ امتیاز)
        SystemSetting::query()->updateOrCreate(
            ['key' => 'qualification_thresholds'],
            [
                'value' => [
                    'representative_points' => 300, // ۳ درگاه
                    'sales_manager_points' => 300,
                    'development_manager_points' => 300,
                ],
                'value_type' => 'json',
                'is_public' => true,
            ]
        );

        $rep = User::query()->where('mobile', '09125555555')->firstOrFail();
        $sm = User::query()->where('mobile', '09123333333')->firstOrFail();
        $dm = User::query()->where('mobile', '09122222222')->firstOrFail();
        $senior = User::query()->where('mobile', '09121111111')->firstOrFail();
        $roles = Role::query()->get()->keyBy('slug');
        $qual = app(QualificationService::class);
        $txs = app(FinopalTransactionService::class);
        // ۳ درگاه موفق برای نماینده اصلی → حد نصاب ۳۰۰ امتیاز این ماه
        $merchants = [];
        for ($i = 1; $i <= 3; $i++) {
            $external = 'GW-BONUS-LAB-'.$i;
            $merchant = 'fino-bonus-lab-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT);
            $gateway = Gateway::query()->updateOrCreate(
                ['external_id' => $external],
                [
                    'merchant_code' => $merchant,
                    'name' => "درگاه لاب پاداش #{$i}",
                    'source' => 'lab',
                    'sale_amount' => 1000000,
                    'is_active' => true,
                ]
            );
            $sale = GatewaySale::query()->updateOrCreate(
                ['idempotency_key' => 'bonus-lab-sale-'.$i],
                [
                    'gateway_id' => $gateway->id,
                    'external_id' => $external,
                    'amount' => 1000000,
                    'full_sales_points' => 100,
                    'status' => 'successful',
                    'source' => 'lab',
                    'sold_at' => now()->subHours(4 - $i),
                ]
            );
            GatewayRepresentative::query()->updateOrCreate(
                ['gateway_sale_id' => $sale->id, 'user_id' => $rep->id],
                ['share_percent' => 100, 'sales_points' => 100]
            );
            foreach ([
                ['user' => $sm, 'slug' => 'sales_manager'],
                ['user' => $dm, 'slug' => 'development_manager'],
                ['user' => $senior, 'slug' => 'senior_manager'],
            ] as $mgr) {
                GatewayManager::query()->updateOrCreate(
                    [
                        'gateway_sale_id' => $sale->id,
                        'user_id' => $mgr['user']->id,
                        'role_id' => $roles[$mgr['slug']]->id,
                    ],
                    ['commission_percent' => 0]
                );
            }
            $merchants[] = $merchant;
        }

        // قفل دائمی درگاه‌ها پس از حد نصاب
        $at = now();
        $qual->syncPermanentEligibilities('representative', $rep, $roles['representative']->id, $at);
        $qual->syncPermanentEligibilities('sales_manager', $sm, $roles['sales_manager']->id, $at);
        $qual->syncPermanentEligibilities('development_manager', $dm, $roles['development_manager']->id, $at);

        // یک تراکنش روی درگاه واجد شرایط → پایه + پاداش ماهانه
        $tx = $txs->ingest([
            'event' => 'transaction.verified',
            'merchant_id' => $merchants[0],
            'authority' => 'BONUS_LAB_TX_'.now()->format('YmdHis'),
            'amount' => 1000000,
            'profit' => 100000,
            'currency' => 'IRT',
            'status' => 'OK',
            'code' => 100,
        ]);

        $this->command?->info('لاب پاداش ماهانه آماده شد.');
        $this->command?->table(
            ['مورد', 'مقدار'],
            [
                ['آستانه تست (امتیاز)', '۳۰۰ (= ۳ درگاه × ۱۰۰)'],
                ['نماینده', '09125555555 / Password123!'],
                ['مدیر فروش', '09123333333'],
                ['مدیر توسعه', '09122222222'],
                ['مدیر ارشد', '09121111111'],
                ['مرچنت‌های واجد شرایط', implode(', ', $merchants)],
                ['تراکنش نمونه', 'tx#'.$tx->id.' profit=100000 روی '.$merchants[0]],
                ['پاداش نماینده (دلتا)', '۵٪ × سود منتسب درگاه‌های واجد شرایط'],
                ['صفحه چک', '/dashboard/representative/monthly-bonus و /dashboard/senior-manager/monthly-bonus'],
            ]
        );
        $this->command?->comment('برای بازگرداندن آستانه‌های تولید: نماینده ۱۰۰۰ / SM ۵۰۰۰ / DM ۲۰۰۰۰ در تنظیمات سوپریوزر.');
    }
}
