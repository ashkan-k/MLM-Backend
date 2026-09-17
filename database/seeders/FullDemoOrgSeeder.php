<?php

namespace Database\Seeders;

use App\Models\Course;
use App\Models\ReferralCode;
use App\Models\RepresentativeReferral;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Services\Gateway\GatewaySaleService;
use App\Services\Integration\Finopal\FinopalTransactionService;
use App\Services\Organization\OrganizationTreeService;
use App\Services\Referral\SelfReferralService;
use App\Services\Referral\SharedLinkService;
use App\Services\Wallet\WalletService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Full test organization (users, tree, sample gateways + txs).
 * Assumes roles / permissions / commission rules already exist (after migrate --seed once).
 */
class FullDemoOrgSeeder extends Seeder
{
    public function run(): void
    {
        $roles = Role::query()->get()->keyBy('slug');
        foreach ([
            'superuser', 'senior_manager', 'development_manager', 'sales_manager',
            'representative', 'representative_referrer',
        ] as $slug) {
            if (! $roles->has($slug)) {
                throw new \RuntimeException("نقش {$slug} وجود ندارد. یک‌بار php artisan migrate:fresh --seed بزنید.");
            }
        }

        $tree = app(OrganizationTreeService::class);
        $wallets = app(WalletService::class);

        $users = [
            'superuser' => ['name' => 'مدیر سامانه', 'mobile' => '09120000000', 'roles' => ['superuser']],
            'senior' => ['name' => 'مدیر ارشد فاینوپال', 'mobile' => '09121111111', 'roles' => ['senior_manager', 'development_manager', 'sales_manager', 'representative']],
            'dev' => ['name' => 'مدیر توسعه', 'mobile' => '09122222222', 'roles' => ['development_manager', 'sales_manager', 'representative']],
            'sales' => ['name' => 'مدیر فروش', 'mobile' => '09123333333', 'roles' => ['sales_manager', 'representative']],
            'referrer' => ['name' => 'نماینده معرف', 'mobile' => '09124444444', 'roles' => ['representative_referrer', 'representative']],
            'rep' => ['name' => 'نماینده اصلی', 'mobile' => '09125555555', 'roles' => ['representative']],
            'multi' => ['name' => 'کاربر چندنقشی', 'mobile' => '09126666666', 'roles' => ['representative', 'representative_referrer', 'sales_manager', 'development_manager']],
            'multi_b' => ['name' => 'کاربر چندنقشی ب', 'mobile' => '09120202020', 'roles' => ['representative', 'representative_referrer', 'sales_manager', 'development_manager']],
            'share_a' => ['name' => 'نماینده اشتراکی الف', 'mobile' => '09127777777', 'roles' => ['representative']],
            'share_b' => ['name' => 'نماینده اشتراکی ب', 'mobile' => '09128888888', 'roles' => ['representative']],
            'outsider' => ['name' => 'شاخه جدا', 'mobile' => '09129999999', 'roles' => ['representative']],
        ];

        $created = [];
        foreach ($users as $key => $row) {
            $user = User::query()->updateOrCreate(
                ['mobile' => $row['mobile']],
                [
                    'name' => $row['name'],
                    'email' => $key.'@finopal.test',
                    'password' => Hash::make('Password123!'),
                    'is_active' => true,
                ]
            );

            foreach ($row['roles'] as $index => $slug) {
                UserRole::query()->updateOrCreate(
                    ['user_id' => $user->id, 'role_id' => $roles[$slug]->id],
                    [
                        'effective_from' => now()->subYear()->toDateString(),
                        'is_primary' => $index === 0,
                        'is_active' => true,
                        'effective_to' => null,
                    ]
                );
                if ($roles[$slug]->is_organizational) {
                    $wallets->walletFor($user, $roles[$slug]);
                }
            }

            ReferralCode::query()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'code' => strtoupper($key).'REF',
                    'source' => 'finopal',
                    'is_active' => true,
                ]
            );

            $created[$key] = $user->fresh('roles');
        }

        // Clear leftover org nodes then rebuild tree
        \App\Models\OrganizationNode::query()->delete();

        $seniorNode = $tree->attach($created['senior'], $roles['senior_manager'], null, now()->subYear()->toDateString());
        $devNode = $tree->attach($created['dev'], $roles['development_manager'], $seniorNode, now()->subYear()->toDateString());
        $salesNode = $tree->attach($created['sales'], $roles['sales_manager'], $devNode, now()->subYear()->toDateString());
        $tree->attach($created['referrer'], $roles['representative'], $salesNode, now()->subMonths(8)->toDateString());
        $tree->attach($created['rep'], $roles['representative'], $salesNode, now()->subMonths(6)->toDateString());
        $tree->attach($created['multi'], $roles['sales_manager'], $devNode, now()->subMonths(10)->toDateString());
        $tree->attach($created['multi_b'], $roles['sales_manager'], $devNode, now()->subMonths(9)->toDateString());
        $tree->attach($created['share_a'], $roles['representative'], $salesNode, now()->subMonths(3)->toDateString());
        $tree->attach($created['share_b'], $roles['representative'], $salesNode, now()->subMonths(3)->toDateString());
        $tree->attach($created['outsider'], $roles['representative'], $seniorNode, now()->subMonths(2)->toDateString());

        $tree->ensureSeniorManagerChain($created['senior']);
        app(SelfReferralService::class)->ensureForSenior($created['senior']->fresh('roles'));

        foreach (['rep', 'share_a', 'share_b'] as $key) {
            RepresentativeReferral::query()->updateOrCreate(
                ['referred_user_id' => $created[$key]->id],
                [
                    'referrer_user_id' => $created['referrer']->id,
                    'source' => 'finopal',
                    'referral_code_id' => ReferralCode::query()->where('user_id', $created['referrer']->id)->value('id'),
                ]
            );
        }

        if (! Course::query()->where('title', 'آموزش سازمان فروش')->exists()) {
            $course = Course::query()->create([
                'title' => 'آموزش سازمان فروش',
                'description' => 'دوره پایه نمایندگان و مدیران',
                'is_active' => true,
                'is_required_for_promotion' => true,
            ]);
            $course->roles()->sync($roles->where('is_organizational', true)->pluck('id'));
            $course->levels()->createMany([
                [
                    'title' => 'آشنایی با محصول',
                    'sort_order' => 1,
                    'passing_score' => 70,
                    'is_active' => true,
                    'content_type' => 'text',
                    'content_body' => "متن آموزشی نمونه:\nسازمان فروش فاینوپال چگونه کار می‌کند.",
                ],
                [
                    'title' => 'مهارت فروش',
                    'sort_order' => 2,
                    'passing_score' => 75,
                    'is_active' => true,
                    'content_type' => 'video',
                    'content_url' => 'https://www.youtube.com/embed/dQw4w9WgXcQ',
                    'content_body' => 'ویدیوی نمونه مهارت فروش.',
                ],
            ]);
        }

        $link = app(SharedLinkService::class)->create($created['share_a'], 'gateway_sale', [
            ['user_id' => $created['share_a']->id, 'share_percent' => '50.000'],
            ['user_id' => $created['share_b']->id, 'share_percent' => '50.000'],
        ]);
        app(SharedLinkService::class)->approve($created['share_b'], $link);

        $sales = app(GatewaySaleService::class);
        $sales->record([
            'external_id' => 'GW-SOLO-1',
            'name' => 'درگاه انفرادی نماینده',
            'amount' => 1000000,
            'representative_user_id' => $created['rep']->id,
            'customer' => ['name' => 'مشتری یک', 'mobile' => '09121230001'],
            'idempotency_key' => 'seed-solo-1',
            'status' => 'successful',
            'merchant_code' => 'fino-seed-solo-0001',
        ]);
        $sales->record([
            'external_id' => 'GW-SHARE-1',
            'name' => 'درگاه اشتراکی ۵۰-۵۰',
            'amount' => 2000000,
            'shared_link_id' => $link->id,
            'customer' => ['name' => 'مشتری اشتراکی', 'mobile' => '09121230002'],
            'idempotency_key' => 'seed-share-1',
            'status' => 'successful',
            'merchant_code' => 'fino-seed-share-0001',
        ]);
        $sales->record([
            'external_id' => 'GW-MULTI-B-1',
            'name' => 'درگاه کاربر چندنقشی ب',
            'amount' => 1800000,
            'representative_user_id' => $created['multi_b']->id,
            'customer' => ['name' => 'مشتری انتقال مزایا', 'mobile' => '09121230077'],
            'idempotency_key' => 'demo-multi-b-gateway-1',
            'status' => 'successful',
            'merchant_code' => 'fino-seed-multib-0001',
        ]);

        $transactions = app(FinopalTransactionService::class);
        foreach ([
            ['merchant_id' => 'fino-seed-solo-0001', 'authority' => 'FP_SEED_SOLO_1', 'amount' => 1000000],
            ['merchant_id' => 'fino-seed-share-0001', 'authority' => 'FP_SEED_SHARE_1', 'amount' => 2000000],
            ['merchant_id' => 'fino-seed-multib-0001', 'authority' => 'FP_SEED_MULTIB_1', 'amount' => 1800000],
        ] as $tx) {
            $transactions->ingest([
                'event' => 'transaction.verified',
                'merchant_id' => $tx['merchant_id'],
                'authority' => $tx['authority'],
                'amount' => $tx['amount'],
                'profit' => $tx['amount'],
                'currency' => 'IRT',
                'status' => 'verified',
                'code' => 100,
            ]);
        }

        $this->call(DemoReviewSeeder::class);
    }
}
