<?php

namespace App\Console\Commands;

use App\Services\Commission\MonthlyBonusService;
use App\Support\JalaliDate;
use Illuminate\Console\Command;

class PayMonthlyBonusesCommand extends Command
{
    protected $signature = 'finopal:pay-monthly-bonuses
        {--month= : ماه به صورت YYYY-MM شمسی از آبان ۱۴۰۵ یا میلادی برای تاریخچه (پیش‌فرض: ماه جاری)}
        {--force : بدون پرسش تأیید}';

    protected $description = 'محاسبه و واریز پاداش ماهانه + انتقال مابقی پرداخت‌نشده به کیف مدیر ارشد';

    public function handle(MonthlyBonusService $bonuses): int
    {
        $month = $this->option('month') ?: JalaliDate::businessMonthKey();
        if (! is_string($month) || ! preg_match('/^\d{4}-\d{2}$/', $month)) {
            $this->error('فرمت ماه نامعتبر است. مثال: 1405-08 یا 2026-10');

            return self::FAILURE;
        }

        try {
            $at = JalaliDate::atBusinessMonthEnd(null, $month);
        } catch (\Throwable) {
            $this->error('فرمت ماه نامعتبر است. مثال: 1405-08 یا 2026-10');

            return self::FAILURE;
        }

        if (! $this->option('force') && $this->input->isInteractive()) {
            if (! $this->confirm("پاداش ماهانه برای {$month} محاسبه و واریز شود؟", true)) {
                return self::SUCCESS;
            }
        }

        $count = $bonuses->refreshMonth($at);
        $this->info("پاداش ماهانه برای {$count} ترکیب کاربر/نقش در ماه {$month} به‌روز شد.");
        $this->comment('مابقی پاداش‌های پرداخت‌نشده (در صورت وجود) به کیف جداگانهٔ پاداش اضافه مدیر ارشد واریز شد.');

        return self::SUCCESS;
    }
}
