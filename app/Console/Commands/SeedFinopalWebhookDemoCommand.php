<?php

namespace App\Console\Commands;

use App\Services\Integration\Finopal\FinopalWebhookDemoService;
use Illuminate\Console\Command;

class SeedFinopalWebhookDemoCommand extends Command
{
    protected $signature = 'finopal:seed-webhook-demo
        {--with-transaction : فقط روی fino-demo-tree-0001 یک تراکنش نمونه هم بزن}
        {--skip-reset : تراکنش/پورسانت/کیف‌پول را پاک نکن (پیش‌فرض: پاک می‌شود)}';

    protected $description = '۴ درگاه تست فاینوپال را آماده می‌کند، کیف پول و تراکنش‌های مرتبط را خالی می‌کند، جدول سهم مورد انتظار را چاپ می‌کند';

    public function handle(FinopalWebhookDemoService $demo): int
    {
        $reset = ! $this->option('skip-reset');
        if ($reset) {
            $this->warn('تراکنش‌ها، پورسانت‌ها و کیف‌پول نقش‌های مرتبط برای ۴ مرچنت تست صفر می‌شود.');
        }

        try {
            $result = $demo->prepare((bool) $this->option('with-transaction'), $reset);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('۴ درگاه تست آماده است. کیف پول و تراکنش‌های قبلی این مرچنت‌ها خالی شد.');
        $this->newLine();
        $this->line('پایه محاسبه در جدول زیر: profit = '.FinopalWebhookDemoService::DEFAULT_PROFIT_IRT.' تومان (IRT)');
        $this->line('در Postman همان مقدار را بفرستید: "profit": 100000, "currency": "IRT"');
        $this->newLine();

        foreach ($result['expected'] as $merchant => $block) {
            $this->comment("━━ {$merchant} ({$block['gateway']}) ━━");
            $this->table(
                ['نقش', 'کاربر', 'موبایل', '٪', 'سهم سود (تومان)', 'یادداشت'],
                collect($block['rows'])->map(fn ($r) => [
                    $r['role_label'],
                    $r['user'],
                    $r['mobile'],
                    $r['percent'],
                    $r['amount'],
                    $r['share_note'] ? 'سهم در فروش '.$r['share_note'].'٪' : '—',
                ])->all()
            );
            $this->line("  جمع پورسانت: {$block['total_commission']} تومان ({$block['total_percent_of_profit']}٪ از profit)");
            $this->newLine();
        }

        $this->info('Postman: authority هر بار جدید (مثلاً FP_TEST_001). merchant_id را از جدول بالا بردارید.');

        if ($result['sample_transaction']) {
            $this->line('تراکنش نمونه fino-demo-tree-0001: id='.$result['sample_transaction']['id']);
        }

        return self::SUCCESS;
    }
}
