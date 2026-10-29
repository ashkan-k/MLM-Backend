<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * پاک‌سازی کامل داده‌های عملیاتی؛ فقط سوپریوزر 09120000000 + کاتالوگ نقش/مجوز/قوانین/جغرافیا می‌ماند.
 */
class WipeKeepSuperuserCommand extends Command
{
    use SkipsBrokenConsoleConfirm;

    protected $signature = 'app:wipe-keep-superuser
                            {--mobile=09120000000 : موبایل سوپریوزری که باید بماند}
                            {--force : بدون تأیید تعاملی}';

    protected $description = 'Wipe all operational DB data except one superuser and catalog tables';

    /** جداولی که ساختار + دادهٔ کاتالوگ‌شان حفظ می‌شود */
    private const KEEP_TABLES = [
        'migrations',
        'roles',
        'permissions',
        'role_permissions',
        'commission_rules',
        'commission_rule_versions',
        'system_settings',
        'catalog_products',
        'geo_states',
        'geo_cities',
        'courses',
        'course_levels',
        'course_level_chapters',
        'course_role_targets',
        'role_dashboard_preferences',
        'users', // فقط ردیف سوپر جداگانه نگه داشته می‌شود
    ];

    public function handle(): int
    {
        $mobile = preg_replace('/\D+/', '', (string) $this->option('mobile')) ?: '09120000000';

        if (! $this->confirmedOrForced(
            "همهٔ داده‌های عملیاتی حذف می‌شوند و فقط کاربر {$mobile} می‌ماند. ادامه؟",
            false
        )) {
            $this->warn('لغو شد.');

            return self::FAILURE;
        }

        $super = User::query()->where('mobile', $mobile)->first();
        if (! $super) {
            $this->error("کاربر با موبایل {$mobile} یافت نشد.");

            return self::FAILURE;
        }

        $superRole = Role::query()->where('slug', 'superuser')->first();
        if (! $superRole) {
            $this->error('نقش superuser در دیتابیس نیست.');

            return self::FAILURE;
        }

        $keepId = (int) $super->id;
        $tables = $this->listTables();
        $toEmpty = array_values(array_filter(
            $tables,
            fn (string $t) => ! in_array($t, self::KEEP_TABLES, true)
        ));

        $this->info('شروع پاک‌سازی… نگهداشت کاربر #'.$keepId.' / '.$mobile);
        $this->line('جداول خالی‌شونده: '.count($toEmpty));

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            foreach ($toEmpty as $table) {
                if (! Schema::hasTable($table)) {
                    continue;
                }
                DB::table($table)->truncate();
                $this->line('truncated '.$table);
            }

            $deletedUsers = User::query()->where('id', '!=', $keepId)->delete();
            $this->info("کاربران حذف‌شده: {$deletedUsers}");

            // اطمینان از نقش سوپر و پاک بودن نقش‌های اضافه
            UserRole::query()->where('user_id', $keepId)->where('role_id', '!=', $superRole->id)->delete();
            UserRole::query()->updateOrCreate(
                ['user_id' => $keepId, 'role_id' => $superRole->id],
                [
                    'effective_from' => now()->subYear()->toDateString(),
                    'effective_to' => null,
                    'is_primary' => true,
                    'is_active' => true,
                ]
            );

            // فیلدهای مکانی بانک اهداف روی سوپر خالی
            if (Schema::hasColumn('users', 'province')) {
                User::query()->whereKey($keepId)->update([
                    'province' => null,
                    'city' => null,
                ]);
            }

            // توکن‌ها / سشن‌ها از قبل truncate شده‌اند
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        $remaining = User::query()->count();
        $roles = UserRole::query()->where('user_id', $keepId)->count();

        $this->newLine();
        $this->info("تمام. کاربران باقی‌مانده: {$remaining} (باید ۱ باشد)");
        $this->info("نقش‌های سوپر: {$roles}");
        $this->comment('سوپریوزر باید دوباره وارد شود (توکن‌ها پاک شدند).');

        return $remaining === 1 ? self::SUCCESS : self::FAILURE;
    }

    /** @return list<string> */
    private function listTables(): array
    {
        $db = DB::getDatabaseName();
        $rows = DB::select(
            'SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ?',
            [$db, 'BASE TABLE']
        );

        return array_values(array_map(
            static fn ($r) => (string) ($r->name ?? $r->NAME ?? ''),
            $rows
        ));
    }
}
