<?php

namespace App\Console\Commands;

use App\Services\Integration\Finopal\ProductOrientedDemoService;
use Illuminate\Console\Command;

class SeedProductOrientedDemoCommand extends Command
{
    protected $signature = 'finopal:seed-product-demo
        {--skip-reset : کیف‌پول/پورسانت مرچنت‌های دمو را صفر نکن}';

    protected $description = 'دیتای mock محصول‌محور: چند فروش درگاه + تیکتینگ + اشتراک با پورسانت همه نقش‌ها';

    public function handle(ProductOrientedDemoService $demo): int
    {
        if (! config('finopal.product_oriented')) {
            $this->warn('FINOPAL_PRODUCT_ORIENTED فعلاً false است — برای دیدن ستون محصول در UI آن را true کنید.');
        }

        try {
            $result = $demo->seed(! $this->option('skip-reset'));
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('دیتای محصول‌محور آماده شد.');
        $this->table(['متریک', 'مقدار'], [
            ['تراکنش درگاه (جدید)', $result['gateway_txs']],
            ['تراکنش محصول (جدید)', $result['product_txs']],
            ['کل تراکنش‌ها', $result['transactions']],
            ['فروش محصول', $result['product_sales']],
            ['کل پورسانت‌ها', $result['commissions']],
        ]);

        $this->newLine();
        $this->comment('کدهای ملی مالک (برای تست وب‌هوک دستی):');
        $this->table(
            ['کلید', 'موبایل', 'کد ملی'],
            collect($result['users'])->map(fn ($u, $k) => [$k, $u['mobile'], $u['national_id']])->values()->all()
        );

        $this->line('لاگین دمو: 09125555555 / 09121111111 / 09120000000 — رمز Password123!');
        $this->line('صفحات: /dashboard/.../gateways ، /commissions ، /wallet ، /superuser/sms');

        return self::SUCCESS;
    }
}
