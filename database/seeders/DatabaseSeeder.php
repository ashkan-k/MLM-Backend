<?php

namespace Database\Seeders;

use App\Models\CommissionRule;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Support\PermissionCatalog;
use Illuminate\Database\Seeder;

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

        PermissionCatalog::sync();

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
                    'sales_manager_points' => 5000,
                    'development_manager_points' => 20000,
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
            [
                'key' => 'shared_link_features',
                'value' => json_encode([
                    'referral_enabled' => true,
                    'gateway_sale_enabled' => true,
                ]),
                'value_type' => 'json',
                'is_public' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // Full demo users / tree / sample sales (also: finopal:seed-full-demo).
        $this->call(FullDemoOrgSeeder::class);

        $this->call(GeoSeeder::class);
    }
}
