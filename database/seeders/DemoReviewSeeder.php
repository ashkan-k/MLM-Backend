<?php

namespace Database\Seeders;

use App\Models\Course;
use App\Models\Notification;
use App\Models\OrganizationNode;
use App\Models\PromotionRequest;
use App\Models\ReferralCode;
use App\Models\Role;
use App\Models\User;
use App\Models\UserCourseProgress;
use App\Models\UserRole;
use App\Services\Gateway\GatewaySaleService;
use App\Services\Organization\OrganizationTreeService;
use App\Services\Promotion\PromotionService;
use App\Services\Wallet\WalletService;
use App\Support\Money;
use App\Support\PermissionCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoReviewSeeder extends Seeder
{
    public function run(): void
    {
        PermissionCatalog::sync();
        Role::query()->where('slug', 'superuser')->update(['name' => 'مدیر سامانه']);
        User::query()->where('mobile', '09120000000')->update(['name' => 'مدیر سامانه']);

        $senior = User::query()->where('mobile', '09121111111')->first();
        $rep = User::query()->where('mobile', '09125555555')->first();
        $referrer = User::query()->where('mobile', '09124444444')->first();
        $sales = User::query()->where('mobile', '09123333333')->first();
        $shareA = User::query()->where('mobile', '09127777777')->first();
        $shareB = User::query()->where('mobile', '09128888888')->first();
        $outsider = User::query()->where('mobile', '09129999999')->first();
        $dev = User::query()->where('mobile', '09122222222')->first();
        if (! $senior) {
            return;
        }

        $walletService = app(WalletService::class);
        $multiB = $this->ensureMultiRoleTransferUser($dev, $walletService);
        foreach ($senior->roles as $role) {
            if ($role->slug === 'superuser') {
                continue;
            }
            $wallet = $walletService->walletFor($senior, $role);
            if (Money::cmp($wallet->availableBalance(), '500000') < 0) {
                $walletService->credit(
                    $wallet,
                    '2000000.000',
                    'adjustment',
                    'demo-wallet-topup-'.$senior->id.'-'.$role->id.'-v2'
                );
            }
        }

        $promotions = app(PromotionService::class);
        $pending = [
            [$shareB, 'representative', 'sales_manager'],
            [$outsider, 'representative', 'sales_manager'],
            [$referrer, 'representative', 'sales_manager'],
            [$rep, 'representative', 'sales_manager'],
            [$shareA, 'representative', 'sales_manager'],
            [$sales, 'sales_manager', 'development_manager'],
            [$rep, 'sales_manager', 'development_manager'],
            [$shareA, 'sales_manager', 'development_manager'],
        ];
        foreach ($pending as [$user, $from, $target]) {
            if (! $user || $user->hasRole($target) || ! $user->hasRole($from)) {
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

        $dev = $dev ?? User::query()->where('mobile', '09122222222')->first();
        $shareB = $shareB ?? User::query()->where('mobile', '09128888888')->first();
        $courses = Course::query()->with('levels')->where('is_active', true)->get();
        foreach ($courses as $course) {
            foreach ($course->levels as $index => $level) {
                if ($level->content_body || $level->content_url) {
                    continue;
                }
                $level->update($index === 0
                    ? [
                        'content_type' => 'text',
                        'content_body' => 'متن آموزشی نمونه برای سطح آشنایی با محصول. این محتوا برای تست پنل آموزش است.',
                    ]
                    : [
                        'content_type' => 'pdf',
                        'content_body' => 'برای این سطح می‌توانید PDF، ویدیو یا فایل آموزشی بارگذاری کنید.',
                    ]);
            }
        }
        foreach ([$rep, $sales, $shareA, $referrer, $dev, $shareB, $multiB] as $index => $user) {
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

    private function ensureMultiRoleTransferUser(?User $dev, WalletService $wallets): ?User
    {
        $user = $this->ensureTransferSourceUser(
            $dev,
            $wallets,
            '09120202020',
            'کاربر چندنقشی ب',
            'multi_b@finopal.test',
            'MULTIBREF',
            9
        );
        if (! $user) {
            return null;
        }

        if (! $user->is_active) {
            $spare = $this->ensureTransferSourceUser(
                $dev,
                $wallets,
                '09120303030',
                'کاربر چندنقشی ج',
                'multi_c@finopal.test',
                'MULTICREF',
                8
            );
            if ($spare?->is_active) {
                $this->seedTransferSourceActivity($spare, $wallets, 'multi-c');
            }

            return $user;
        }

        $this->seedTransferSourceActivity($user, $wallets, 'multi-b');

        return $user;
    }

    private function ensureTransferSourceUser(
        ?User $dev,
        WalletService $wallets,
        string $mobile,
        string $name,
        string $email,
        string $referralCode,
        int $monthsAgo,
    ): ?User {
        $roles = Role::query()->whereIn('slug', [
            'representative',
            'representative_referrer',
            'sales_manager',
            'development_manager',
        ])->get()->keyBy('slug');
        if ($roles->count() < 4) {
            return null;
        }

        $user = User::query()->firstOrCreate(
            ['mobile' => $mobile],
            [
                'name' => $name,
                'email' => $email,
                'password' => Hash::make('Password123!'),
                'is_active' => true,
            ]
        );

        if (! $user->is_active) {
            return $user;
        }

        foreach ($roles as $role) {
            UserRole::query()->updateOrCreate(
                ['user_id' => $user->id, 'role_id' => $role->id],
                [
                    'effective_from' => now()->subYear()->toDateString(),
                    'is_primary' => $role->slug === 'representative',
                    'is_active' => true,
                    'effective_to' => null,
                ]
            );
            $wallets->walletFor($user, $role);
        }

        ReferralCode::query()->firstOrCreate(
            ['user_id' => $user->id, 'code' => $referralCode],
            ['source' => 'finopal', 'is_active' => true]
        );

        $parent = $dev
            ? OrganizationNode::query()->where('user_id', $dev->id)->where('is_active', true)->first()
            : null;
        $hasNode = OrganizationNode::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->exists();
        if (! $hasNode && $roles->has('sales_manager')) {
            app(OrganizationTreeService::class)->attach(
                $user,
                $roles['sales_manager'],
                $parent,
                now()->subMonths($monthsAgo)->toDateString()
            );
        }

        return $user;
    }

    private function seedTransferSourceActivity(User $user, WalletService $wallets, string $prefix): void
    {
        $sales = app(GatewaySaleService::class);
        $sales->record([
            'external_id' => 'GW-'.strtoupper($prefix).'-1',
            'name' => 'درگاه کاربر چندنقشی ب',
            'amount' => 1800000,
            'representative_user_id' => $user->id,
            'customer' => ['name' => 'مشتری انتقال مزایا', 'mobile' => '09121230077'],
            'idempotency_key' => 'demo-'.$prefix.'-gateway-1',
        ]);
        $sales->record([
            'external_id' => 'GW-'.strtoupper($prefix).'-2',
            'name' => 'درگاه فروشگاهی ب',
            'amount' => 2450000,
            'representative_user_id' => $user->id,
            'customer' => ['name' => 'فروشگاه نمونه انتقال', 'mobile' => '09121230078'],
            'idempotency_key' => 'demo-'.$prefix.'-gateway-2',
        ]);
        $sales->record([
            'external_id' => 'GW-'.strtoupper($prefix).'-3',
            'name' => 'درگاه خدماتی ب',
            'amount' => 980000,
            'representative_user_id' => $user->id,
            'customer' => ['name' => 'مشتری خدماتی انتقال', 'mobile' => '09121230079'],
            'idempotency_key' => 'demo-'.$prefix.'-gateway-3',
        ]);

        foreach ($user->fresh()->roles as $role) {
            if (! in_array($role->slug, ['representative', 'sales_manager', 'development_manager'], true)) {
                continue;
            }
            $wallet = $wallets->walletFor($user, $role);
            $amount = match ($role->slug) {
                'representative' => '750000.000',
                'sales_manager' => '1250000.000',
                default => '320000.000',
            };
            if (Money::cmp($wallet->availableBalance(), $amount) < 0) {
                $wallets->credit(
                    $wallet,
                    $amount,
                    'adjustment',
                    'demo-'.$prefix.'-wallet-'.$role->slug
                );
            }
        }

        Notification::query()->firstOrCreate(
            [
                'user_id' => $user->id,
                'title' => 'موجودی و پورسانت آماده انتقال',
            ],
            [
                'type' => 'system.info',
                'body' => 'برای این حساب پورسانت درگاه، موجودی کیف پول و فعالیت ثبت شده تا انتقال مزایا قابل مشاهده باشد.',
                'data' => ['demo' => true, 'path' => 'wallet'],
            ]
        );
    }
}
