<?php

namespace App\Console\Commands;

use App\Services\Organization\EmptyOrgLabService;
use Illuminate\Console\Command;

class SeedEmptyOrgLabCommand extends Command
{
    use SkipsBrokenConsoleConfirm;

    protected $signature = 'finopal:seed-empty-org
        {--purge : همه کاربران به‌جز superuser و داده‌های عملیاتی‌شان را پاک می‌کند، سپس فقط یک مدیر ارشد می‌سازد}
        {--force : بدون پرسش تأیید اجرا شود (برای ویندوز/مسیر فارسی توصیه‌شده)}';

    protected $description = 'سازمان کاملاً خالی: فقط superuser + یک مدیر ارشد با نقش‌های SM/DM/senior (بدون نماینده)';

    public function handle(EmptyOrgLabService $lab): int
    {
        if ($this->option('purge')) {
            if (! $this->confirmedOrForced('همه کاربران غیر از superuser پاک شوند؟ این عمل برگشت‌ناپذیر است.', true)) {
                $this->warn('لغو شد.');

                return self::SUCCESS;
            }
            $stats = $lab->purgeNonSuperusers();
            $this->warn('پاک‌سازی انجام شد. کاربران حذف‌شده: '.$stats['deleted_users'].' | superuser نگه‌داشته: '.implode(',', $stats['kept_superuser_ids']));
        }

        $result = $lab->seedEmptyOrg();

        $this->info('سازمان خالی آماده است (فقط سوپریوزر + مدیر ارشد).');
        $this->newLine();
        $this->table(
            ['کلید', 'موبایل', 'رمز', 'نقش‌ها'],
            collect($result['users'])->map(fn ($u, $key) => [
                $key,
                $u['mobile'],
                $u['password'],
                is_array($u['roles']) ? implode(', ', $u['roles']) : $u['roles'],
            ])->values()->all()
        );
        $this->newLine();
        foreach ($result['notes'] as $note) {
            $this->line('• '.$note);
        }
        $this->newLine();
        $this->comment('تست دستی سناریو ۳ و ۴:');
        $this->line('1) ورود ارشد 09121111111 → درخت سازمان باید فقط خودش باشد');
        $this->line('2) ثبت چند نماینده با کد معرف ارشد → همه زیر SM خودِ ارشد');
        $this->line('3) یکی از نمایندگان چند نفر را معرفی کند، سپس به مدیر فروش ارتقاء یابد');
        $this->line('4) بعد از ارتقاء: گره نمایندگی خودش + معرف‌هایش زیر SM جدید قرار می‌گیرند');

        return self::SUCCESS;
    }
}
