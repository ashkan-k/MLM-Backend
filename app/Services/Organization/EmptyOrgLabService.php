<?php

namespace App\Services\Organization;

use App\Models\ReferralCode;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Services\Referral\SelfReferralService;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * Lab seed for employer scenarios:
 * - Empty org: senior holds SM/DM/senior for all reps
 * - Later reassign SM/DM and promotion rehome
 */
class EmptyOrgLabService
{
    public function __construct(
        private readonly OrganizationTreeService $tree,
        private readonly WalletService $wallets,
        private readonly SelfReferralService $selfReferral,
    ) {}

    /**
     * Delete every user except superuser(s) and wipe related operational data.
     * Keeps roles, permissions, commission rules, courses, system settings, geo.
     */
    public function purgeNonSuperusers(): array
    {
        $superIds = User::query()
            ->whereHas('roles', fn ($q) => $q->where('slug', 'superuser'))
            ->pluck('id')
            ->all();

        if ($superIds === []) {
            throw new \RuntimeException('هیچ superuserای یافت نشد؛ پاک‌سازی متوقف شد.');
        }

        return DB::transaction(function () use ($superIds) {
            $deletedUsers = User::query()->whereNotIn('id', $superIds)->count();

            // Finance / ops first (avoid orphan FKs) — use table deletes for MySQL reliability
            DB::table('commissions')->delete();
            DB::table('finopal_transactions')->delete();
            if (Schema::hasTable('withdrawal_approvals')) {
                DB::table('withdrawal_approvals')->delete();
            }
            DB::table('wallet_transactions')->delete();
            DB::table('withdrawal_requests')->delete();
            DB::table('notifications')->delete();
            if (Schema::hasTable('manager_feedback')) {
                DB::table('manager_feedback')->delete();
            }
            if (Schema::hasTable('promotion_criteria_results')) {
                DB::table('promotion_criteria_results')->delete();
            }
            DB::table('promotion_requests')->delete();

            if (Schema::hasTable('benefit_transfer_items')) {
                DB::table('benefit_transfer_items')->delete();
            }
            if (Schema::hasTable('benefit_transfers')) {
                DB::table('benefit_transfers')->delete();
            }

            if (Schema::hasTable('message_reads')) {
                DB::table('message_reads')->delete();
            }
            if (Schema::hasTable('messages')) {
                DB::table('messages')->delete();
            }
            if (Schema::hasTable('conversation_authorizations')) {
                DB::table('conversation_authorizations')->delete();
            }
            if (Schema::hasTable('conversation_participants')) {
                DB::table('conversation_participants')->delete();
            }
            if (Schema::hasTable('conversations')) {
                DB::table('conversations')->delete();
            }

            if (Schema::hasTable('gateway_sale_reviews')) {
                DB::table('gateway_sale_reviews')->delete();
            }
            if (Schema::hasTable('gateway_representatives')) {
                DB::table('gateway_representatives')->delete();
            }
            if (Schema::hasTable('gateway_referrers')) {
                DB::table('gateway_referrers')->delete();
            }
            if (Schema::hasTable('gateway_managers')) {
                DB::table('gateway_managers')->delete();
            }
            DB::table('gateway_sales')->delete();
            DB::table('gateways')->delete();
            DB::table('customers')->delete();

            if (Schema::hasTable('referral_share_members')) {
                DB::table('referral_share_members')->delete();
            }
            DB::table('representative_referrals')->delete();
            if (Schema::hasTable('shared_link_members')) {
                DB::table('shared_link_members')->delete();
            }
            DB::table('shared_links')->delete();
            DB::table('referral_codes')->whereNotIn('user_id', $superIds)->delete();

            if (Schema::hasTable('user_course_progress')) {
                DB::table('user_course_progress')->whereNotIn('user_id', $superIds)->delete();
            }
            if (Schema::hasTable('role_dashboard_preferences')) {
                DB::table('role_dashboard_preferences')->whereNotIn('user_id', $superIds)->delete();
            }
            if (Schema::hasTable('sessions')) {
                DB::table('sessions')->delete();
            }

            DB::table('organization_nodes')->delete();
            DB::table('wallets')->whereNotIn('user_id', $superIds)->delete();
            DB::table('user_roles')->whereNotIn('user_id', $superIds)->delete();
            if (Schema::hasTable('personal_access_tokens')) {
                DB::table('personal_access_tokens')->whereNotIn('user_id', $superIds)->delete();
            }
            if (Schema::hasTable('user_permissions')) {
                DB::table('user_permissions')->whereNotIn('user_id', $superIds)->delete();
            }

            // Null actor refs that may point at deleted users
            if (Schema::hasColumn('audit_logs', 'actor_user_id')) {
                DB::table('audit_logs')->whereNotIn('actor_user_id', $superIds)->update(['actor_user_id' => null]);
            }

            User::query()->whereNotIn('id', $superIds)->delete();

            return [
                'kept_superuser_ids' => $superIds,
                'deleted_users' => $deletedUsers,
            ];
        });
    }

    /**
     * Truly empty organization: only senior (+ existing superuser).
     * Senior holds SM+DM+senior(+rep) so new reps attach under senior's SM slot
     * until real managers are promoted over time.
     *
     * @return array{users: array<string, array{mobile: string, password: string, roles: list<string>}>, notes: list<string>}
     */
    public function seedEmptyOrg(): array
    {
        $roles = Role::query()->get()->keyBy('slug');
        foreach (['senior_manager', 'development_manager', 'sales_manager', 'representative', 'representative_referrer'] as $slug) {
            if (! $roles->has($slug)) {
                throw new \RuntimeException("نقش {$slug} در دیتابیس نیست. اول migrate --seed بزنید.");
            }
        }

        return DB::transaction(function () use ($roles) {
            $password = 'Password123!';

            $senior = $this->upsertUser('مدیر ارشد', '09121111111', $password, [
                'senior_manager', 'development_manager', 'sales_manager', 'representative', 'representative_referrer',
            ], $roles);

            $this->tree->ensureSeniorManagerChain($senior);
            $this->selfReferral->ensureForSenior($senior->fresh('roles'));

            return [
                'users' => [
                    'superuser' => ['mobile' => '09120000000', 'password' => $password, 'roles' => ['superuser']],
                    'senior' => [
                        'mobile' => '09121111111',
                        'password' => $password,
                        'roles' => ['senior_manager', 'development_manager', 'sales_manager', 'representative', 'representative_referrer'],
                    ],
                ],
                'notes' => [
                    'فقط سوپریوزر و یک مدیر ارشد باقی مانده‌اند؛ زیرمجموعه‌ای ساخته نشده است.',
                    'گره مدیر فروش / توسعه / ارشد متعلق به خود مدیر ارشد است تا نمایندگان جدید زیر SM او ثبت شوند.',
                    'سناریو ۳: نماینده‌ها را با کد معرف ارشد ثبت کنید → همه زیر SM ارشد می‌آیند.',
                    'سناریو ۴: بعد از ارتقاء یک نماینده به مدیر فروش، کد نمایندگی خودش و معرف‌هایش زیر SM جدید منتقل می‌شوند.',
                ],
            ];
        });
    }

    private function upsertUser(string $name, string $mobile, string $password, array $roleSlugs, $roles): User
    {
        $user = User::query()->updateOrCreate(
            ['mobile' => $mobile],
            [
                'name' => $name,
                'password' => Hash::make($password),
                'is_active' => true,
            ]
        );

        foreach ($roleSlugs as $index => $slug) {
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
                $this->wallets->walletFor($user, $roles[$slug]);
            }
        }

        ReferralCode::query()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'code' => strtoupper(preg_replace('/\D/', '', $mobile)).'REF',
                'source' => 'finopal',
                'is_active' => true,
            ]
        );

        return $user->fresh('roles');
    }
}
