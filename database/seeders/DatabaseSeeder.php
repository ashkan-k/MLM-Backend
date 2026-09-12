<?php

namespace Database\Seeders;

use App\Models\CommissionRule;
use App\Models\Course;
use App\Models\Permission;
use App\Models\ReferralCode;
use App\Models\RepresentativeReferral;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\UserRole;
use App\Services\Gateway\GatewaySaleService;
use App\Services\Organization\OrganizationTreeService;
use App\Services\Referral\SharedLinkService;
use App\Services\Wallet\WalletService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $roles = collect(config('finopal.roles'))->mapWithKeys(function ($meta, $slug) {
            $role = Role::query()->create([
                'name' => $meta['name'],
                'slug' => $slug,
                'hierarchy_level' => $meta['level'],
                'is_organizational' => $meta['organizational'],
                'is_active' => true,
            ]);

            return [$slug => $role];
        });

        $permissions = [
            'representative.gateway.view',
            'representative.gateway.create',
            'representative_referrer.team.view',
            'sales_manager.team.view',
            'sales_manager.team.message',
            'development_manager.team.view',
            'senior_manager.withdrawal.approve',
            'senior_manager.benefit_transfer.create',
            'senior_manager.promotion.decide',
            'superuser.commission_rules.update',
            'superuser.gateway.create',
            'chat.cross_branch.message',
        ];

        foreach ($permissions as $slug) {
            [$panel, $module, $action] = explode('.', $slug);
            $permission = Permission::query()->create([
                'name' => $slug,
                'slug' => $slug,
                'panel' => $panel,
                'module' => $module,
                'action' => $action,
                'is_active' => true,
            ]);

            foreach ($roles as $role) {
                $allowed = $role->slug === 'superuser'
                    || ($slug !== 'chat.cross_branch.message' && str_starts_with($slug, $role->slug.'.'));
                if ($allowed) {
                    $role->permissions()->attach($permission->id, ['allowed' => true]);
                }
            }
        }

        $rules = [
            ['code' => 'senior_manager', 'name' => 'مدیر ارشد', 'percent' => '4.000', 'qualified' => '4.000', 'type' => null],
            ['code' => 'development_manager', 'name' => 'مدیر توسعه', 'percent' => '4.500', 'qualified' => '6.000', 'type' => 'monthly_gateways'],
            ['code' => 'sales_manager', 'name' => 'مدیر فروش', 'percent' => '6.000', 'qualified' => '8.000', 'type' => 'monthly_gateways'],
            ['code' => 'representative_referrer', 'name' => 'نماینده معرف', 'percent' => '2.000', 'qualified' => '2.000', 'type' => null],
            ['code' => 'representative', 'name' => 'نماینده', 'percent' => '15.000', 'qualified' => '20.000', 'type' => 'monthly_points'],
        ];

        foreach ($rules as $row) {
            $rule = CommissionRule::query()->create([
                'code' => $row['code'],
                'name' => $row['name'],
                'default_percent' => $row['percent'],
                'qualification_type' => $row['type'],
                'conditions' => ['qualified_percent' => $row['qualified']],
                'is_active' => true,
            ]);
            $rule->versions()->create([
                'version' => 1,
                'percent' => $row['percent'],
                'qualified_percent' => $row['qualified'],
                'conditions' => $row,
                'effective_from' => now()->subYear(),
            ]);
        }

        SystemSetting::query()->insert([
            [
                'key' => 'qualification_thresholds',
                'value' => json_encode([
                    'representative_points' => 1000,
                    'sales_manager_gateways' => 50,
                    'development_manager_gateways' => 200,
                ]),
                'value_type' => 'json',
                'is_public' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'promotion_criteria',
                'value' => json_encode([
                    'sm_personal_points' => 10000,
                    'sm_new_reps' => 60,
                    'sm_strong_reps' => 24,
                    'sm_rep_points' => 5000,
                    'dm_years' => 1,
                    'dm_new_reps' => 100,
                    'dm_strong_reps' => 30,
                    'dm_rep_points' => 10000,
                    'dm_eligible_sms' => 2,
                ]),
                'value_type' => 'json',
                'is_public' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $tree = app(OrganizationTreeService::class);
        $wallets = app(WalletService::class);

        $users = [
            'superuser' => ['name' => 'سوپریوزر سیستم', 'mobile' => '09120000000', 'roles' => ['superuser']],
            'senior' => ['name' => 'مدیر ارشد فاینوپال', 'mobile' => '09121111111', 'roles' => ['senior_manager', 'development_manager', 'sales_manager', 'representative']],
            'dev' => ['name' => 'مدیر توسعه', 'mobile' => '09122222222', 'roles' => ['development_manager', 'sales_manager', 'representative']],
            'sales' => ['name' => 'مدیر فروش', 'mobile' => '09123333333', 'roles' => ['sales_manager', 'representative']],
            'referrer' => ['name' => 'نماینده معرف', 'mobile' => '09124444444', 'roles' => ['representative_referrer', 'representative']],
            'rep' => ['name' => 'نماینده اصلی', 'mobile' => '09125555555', 'roles' => ['representative']],
            'multi' => ['name' => 'کاربر چندنقشی', 'mobile' => '09126666666', 'roles' => ['representative', 'representative_referrer', 'sales_manager', 'development_manager']],
            'share_a' => ['name' => 'نماینده اشتراکی الف', 'mobile' => '09127777777', 'roles' => ['representative']],
            'share_b' => ['name' => 'نماینده اشتراکی ب', 'mobile' => '09128888888', 'roles' => ['representative']],
            'outsider' => ['name' => 'شاخه جدا', 'mobile' => '09129999999', 'roles' => ['representative']],
        ];

        $created = [];
        foreach ($users as $key => $row) {
            $user = User::query()->create([
                'name' => $row['name'],
                'mobile' => $row['mobile'],
                'email' => $key.'@finopal.test',
                'password' => Hash::make('Password123!'),
                'is_active' => true,
            ]);
            foreach ($row['roles'] as $index => $slug) {
                UserRole::query()->create([
                    'user_id' => $user->id,
                    'role_id' => $roles[$slug]->id,
                    'effective_from' => now()->subYear()->toDateString(),
                    'is_primary' => $index === 0,
                    'is_active' => true,
                ]);
                if ($roles[$slug]->is_organizational) {
                    $wallets->walletFor($user, $roles[$slug]);
                }
            }
            ReferralCode::query()->create([
                'user_id' => $user->id,
                'code' => strtoupper($key).'REF',
                'source' => 'finopal',
                'is_active' => true,
            ]);
            $created[$key] = $user;
        }

        $seniorNode = $tree->attach($created['senior'], $roles['senior_manager'], null, now()->subYear()->toDateString());
        $devNode = $tree->attach($created['dev'], $roles['development_manager'], $seniorNode, now()->subYear()->toDateString());
        $salesNode = $tree->attach($created['sales'], $roles['sales_manager'], $devNode, now()->subYear()->toDateString());
        $tree->attach($created['referrer'], $roles['representative'], $salesNode, now()->subMonths(8)->toDateString());
        $tree->attach($created['rep'], $roles['representative'], $salesNode, now()->subMonths(6)->toDateString());
        $tree->attach($created['multi'], $roles['sales_manager'], $devNode, now()->subMonths(10)->toDateString());
        $tree->attach($created['share_a'], $roles['representative'], $salesNode, now()->subMonths(3)->toDateString());
        $tree->attach($created['share_b'], $roles['representative'], $salesNode, now()->subMonths(3)->toDateString());
        $tree->attach($created['outsider'], $roles['representative'], $seniorNode, now()->subMonths(2)->toDateString());

        foreach (['rep', 'share_a', 'share_b'] as $key) {
            RepresentativeReferral::query()->create([
                'referred_user_id' => $created[$key]->id,
                'referrer_user_id' => $created['referrer']->id,
                'source' => 'finopal',
                'referral_code_id' => ReferralCode::query()->where('user_id', $created['referrer']->id)->value('id'),
            ]);
        }

        $course = Course::query()->create([
            'title' => 'آموزش سازمان فروش',
            'description' => 'دوره پایه نمایندگان و مدیران',
            'is_active' => true,
            'is_required_for_promotion' => true,
        ]);
        $course->roles()->sync($roles->where('is_organizational', true)->pluck('id'));
        $course->levels()->createMany([
            ['title' => 'آشنایی با محصول', 'sort_order' => 1, 'passing_score' => 70, 'is_active' => true],
            ['title' => 'مهارت فروش', 'sort_order' => 2, 'passing_score' => 75, 'is_active' => true],
        ]);

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
        ]);
        $sales->record([
            'external_id' => 'GW-SHARE-1',
            'name' => 'درگاه اشتراکی ۵۰-۵۰',
            'amount' => 2000000,
            'shared_link_id' => $link->id,
            'customer' => ['name' => 'مشتری اشتراکی', 'mobile' => '09121230002'],
            'idempotency_key' => 'seed-share-1',
        ]);
    }
}
