<?php

namespace App\Console\Commands;

use App\Services\Organization\EmptyOrgLabService;
use Database\Seeders\FullDemoOrgSeeder;
use Illuminate\Console\Command;

class SeedFullDemoOrgCommand extends Command
{
    protected $signature = 'finopal:seed-full-demo
        {--purge : قبل از وارد کردن، همه کاربران غیر از superuser و داده‌های عملیاتی را پاک می‌کند (پیشنهادی)}
        {--force : بدون پرسش تأیید اجرا شود (برای ویندوز/مسیر فارسی توصیه‌شده)}';

    protected $description = 'وارد کردن مجدد دیتای کامل تستی سازمان (کاربران، درخت، درگاه‌ها، تراکنش‌ها، دموی بررسی)';

    public function handle(EmptyOrgLabService $lab): int
    {
        if ($this->option('purge')) {
            if (! $this->option('force') && $this->input->isInteractive()) {
                if (! $this->confirm('همه کاربران غیر از superuser پاک شوند و دیتای کامل تستی دوباره ساخته شود؟', true)) {
                    $this->warn('لغو شد.');

                    return self::SUCCESS;
                }
            }
            $stats = $lab->purgeNonSuperusers();
            $this->warn('پاک‌سازی انجام شد. کاربران حذف‌شده: '.$stats['deleted_users']);
        } else {
            $this->comment('بدون --purge اجرا می‌شود؛ اگر موبایل‌های دمو از قبل باشند ممکن است تداخل پیش بیاید. برای ریست تمیز از --purge استفاده کنید.');
        }

        $seeder = new FullDemoOrgSeeder;
        $seeder->setCommand($this);
        $seeder->run();

        $this->newLine();
        $this->info('دیتای کامل تستی سازمان آماده است.');
        $this->table(
            ['نقش / کاربر', 'موبایل', 'رمز'],
            [
                ['مدیر سامانه', '09120000000', 'Password123!'],
                ['مدیر ارشد', '09121111111', 'Password123!'],
                ['مدیر توسعه', '09122222222', 'Password123!'],
                ['مدیر فروش', '09123333333', 'Password123!'],
                ['نماینده معرف', '09124444444', 'Password123!'],
                ['نماینده اصلی', '09125555555', 'Password123!'],
                ['نماینده اشتراکی الف', '09127777777', 'Password123!'],
                ['نماینده اشتراکی ب', '09128888888', 'Password123!'],
                ['کاربر چندنقشی', '09126666666', 'Password123!'],
                ['کاربر چندنقشی ب', '09120202020', 'Password123!'],
            ]
        );
        $this->line('کد معرف نمونه: SENIORREF ، REPREF ، SHARE_AREF ، …');
        $this->line('درگاه‌های نمونه: fino-seed-solo-0001 ، fino-seed-share-0001 ، fino-seed-multib-0001');

        return self::SUCCESS;
    }
}
