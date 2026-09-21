<?php

namespace App\Console\Commands;

use App\Services\Commission\MonthlyBonusService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class PayMonthlyBonusesCommand extends Command
{
    protected $signature = 'finopal:pay-monthly-bonuses
        {--month= : ماه به صورت YYYY-MM (پیش‌فرض: ماه جاری)}
        {--force : بدون پرسش تأیید}';

    protected $description = 'محاسبه و واریز پاداش ماهانه + انتقال مابقی پرداخت‌نشده به کیف مدیر ارشد';

    public function handle(MonthlyBonusService $bonuses): int
    {
        $month = $this->option('month') ?: now()->format('Y-m');
        try {
            $at = Carbon::createFromFormat('Y-m', $month)->endOfMonth();
        } catch (\Throwable) {
            $this->error('فرمت ماه نامعتبر است. مثال: 2026-09');

            return self::FAILURE;
        }

        if (! $this->option('force') && $this->input->isInteractive()) {
            if (! $this->confirm("پاداش ماهانه برای {$month} محاسبه و واریز شود؟", true)) {
                return self::SUCCESS;
            }
        }

        $count = $bonuses->refreshMonth($at);
        $this->info("پاداش ماهانه برای {$count} ترکیب کاربر/نقش در ماه {$month} به‌روز شد.");
        $this->comment('مابقی پاداش‌های پرداخت‌نشده (در صورت وجود) به کیف مدیر ارشد واریز شد.');

        return self::SUCCESS;
    }
}
