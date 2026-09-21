<?php

namespace App\Console\Commands;

use App\Services\Organization\EmptyOrgLabService;
use Database\Seeders\FullDemoOrgSeeder;
use Illuminate\Console\Command;

/**
 * One-shot import of the standard demo accounts (Password123!).
 */
class SeedDemoUsersCommand extends Command
{
    use SkipsBrokenConsoleConfirm;

    protected $signature = 'finopal:seed-demo-users
        {--purge : قبل از ایمپورت، همه کاربران غیر از superuser را پاک می‌کند}
        {--force : بدون پرسش تأیید (روی ویندوز/مسیر فارسی توصیه‌شده)}
        {--with-webhook : بعد از کاربران، finopal:seed-webhook-demo هم اجرا شود}';

    protected $description = 'ایمپورت کاربران پیش‌فرض دمو (سوپریوزر، مدیران، معرف، نماینده، اشتراکی) با رمز Password123!';

    public function handle(EmptyOrgLabService $lab): int
    {
        if ($this->option('purge')) {
            if (! $this->confirmedOrForced('کاربران غیر از superuser پاک و اکانت‌های دمو دوباره ساخته شوند؟', true)) {
                $this->warn('لغو شد.');

                return self::SUCCESS;
            }
            $stats = $lab->purgeNonSuperusers();
            $this->warn('پاک‌سازی: '.$stats['deleted_users'].' کاربر حذف شد.');
        }

        $seeder = new FullDemoOrgSeeder;
        $seeder->setCommand($this);
        $seeder->run();

        $this->newLine();
        $this->info('کاربران پیش‌فرض آماده است. فرانت: http://127.0.0.1:5173/login');
        $this->table(
            ['موبایل', 'نقش', 'رمز'],
            [
                ['09120000000', 'سوپریوزر', 'Password123!'],
                ['09121111111', 'مدیر ارشد', 'Password123!'],
                ['09122222222', 'مدیر توسعه', 'Password123!'],
                ['09123333333', 'مدیر فروش', 'Password123!'],
                ['09124444444', 'معرف', 'Password123!'],
                ['09125555555', 'نماینده', 'Password123!'],
                ['09127777777', 'اشتراکی الف', 'Password123!'],
                ['09128888888', 'اشتراکی ب', 'Password123!'],
                ['09126666666', 'چندنقشی', 'Password123!'],
                ['09120202020', 'چندنقشی ب', 'Password123!'],
            ]
        );

        if ($this->option('with-webhook')) {
            $this->call('finopal:seed-webhook-demo');
        } else {
            $this->comment('برای مرچنت‌های Postman: php artisan finopal:seed-webhook-demo');
        }

        return self::SUCCESS;
    }
}
