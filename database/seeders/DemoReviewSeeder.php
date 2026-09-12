<?php

namespace Database\Seeders;

use App\Models\Course;
use App\Models\Notification;
use App\Models\PromotionRequest;
use App\Models\Role;
use App\Models\User;
use App\Models\UserCourseProgress;
use App\Services\Promotion\PromotionService;
use Illuminate\Database\Seeder;

class DemoReviewSeeder extends Seeder
{
    public function run(): void
    {
        Role::query()->where('slug', 'superuser')->update(['name' => 'مدیر سامانه']);
        User::query()->where('mobile', '09120000000')->update(['name' => 'مدیر سامانه']);

        $senior = User::query()->where('mobile', '09121111111')->first();
        $rep = User::query()->where('mobile', '09125555555')->first();
        $referrer = User::query()->where('mobile', '09124444444')->first();
        $sales = User::query()->where('mobile', '09123333333')->first();
        $shareA = User::query()->where('mobile', '09127777777')->first();
        if (! $senior) {
            return;
        }

        $promotions = app(PromotionService::class);
        $pending = [
            [$rep, 'representative', 'sales_manager'],
            [$referrer, 'representative', 'sales_manager'],
            [$sales, 'sales_manager', 'development_manager'],
            [$shareA, 'representative', 'sales_manager'],
        ];
        foreach ($pending as [$user, $from, $target]) {
            if (! $user) {
                continue;
            }
            $exists = PromotionRequest::query()
                ->where('user_id', $user->id)
                ->whereHas('targetRole', fn ($q) => $q->where('slug', $target))
                ->where('status', 'pending')
                ->exists();
            if (! $exists) {
                $promotions->request($user, $from, $target);
            }
        }

        $samples = [
            ['type' => 'promotion.pending', 'title' => 'درخواست ارتقاء جدید', 'body' => 'نماینده اصلی برای ارتقاء به مدیر فروش منتظر بررسی شماست.', 'read' => false],
            ['type' => 'promotion.pending', 'title' => 'ارتقاء مدیر فروش', 'body' => 'مدیر فروش درخواست ارتقاء به مدیر توسعه ثبت کرده است.', 'read' => false],
            ['type' => 'withdrawal.pending', 'title' => 'برداشت در انتظار تایید', 'body' => 'یک درخواست برداشت از شبکه شما به مرحله مدیر ارشد رسیده است.', 'read' => false],
            ['type' => 'training.progress', 'title' => 'پیشرفت آموزش شبکه', 'body' => 'چند نفر از زیرمجموعه‌ها دوره آموزش سازمان فروش را تکمیل کرده‌اند.', 'read' => false],
            ['type' => 'shared_link.approval', 'title' => 'لینک اشتراکی جدید', 'body' => 'یک لینک فروش سه‌نفره در شبکه ایجاد شده و منتظر تایید اعضاست.', 'read' => true],
            ['type' => 'system.info', 'title' => 'راهنمای پنل مدیر ارشد', 'body' => 'از صفحات ارتقاء، آموزش و برداشت می‌توانید شبکه را بررسی و تصمیم بگیرید.', 'read' => false],
        ];
        foreach ($samples as $index => $row) {
            $exists = Notification::query()
                ->where('user_id', $senior->id)
                ->where('title', $row['title'])
                ->exists();
            if ($exists) {
                continue;
            }
            $path = match ($row['type']) {
                'promotion.pending' => 'promotions',
                'withdrawal.pending' => 'withdrawals',
                'training.progress' => 'training',
                'shared_link.approval' => 'referrals',
                default => null,
            };
            Notification::query()->create([
                'user_id' => $senior->id,
                'type' => $row['type'],
                'title' => $row['title'],
                'body' => $row['body'],
                'data' => array_filter(['demo' => true, 'path' => $path]),
                'read_at' => $row['read'] ? now()->subHours($index + 1) : null,
                'created_at' => now()->subMinutes(15 * ($index + 1)),
                'updated_at' => now()->subMinutes(15 * ($index + 1)),
            ]);
        }

        $dev = User::query()->where('mobile', '09122222222')->first();
        $shareB = User::query()->where('mobile', '09128888888')->first();
        $courses = Course::query()->with('levels')->where('is_active', true)->get();
        foreach ([$rep, $sales, $shareA, $referrer, $dev, $shareB] as $index => $user) {
            if (! $user) {
                continue;
            }
            foreach ($courses as $course) {
                foreach ($course->levels as $level) {
                    UserCourseProgress::query()->updateOrCreate(
                        [
                            'user_id' => $user->id,
                            'course_id' => $course->id,
                            'course_level_id' => $level->id,
                        ],
                        [
                            'status' => 'completed',
                            'score' => 0,
                            'progress_percent' => 100,
                            'completed_at' => now()->subDays($index + 1),
                        ]
                    );
                }
            }
        }
    }
}
