<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\GatewaySale;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\WithdrawalRequest;
use App\Services\BenefitTransfer\BenefitTransferService;
use App\Services\Gateway\GatewaySaleService;
use App\Services\Integration\FraSoft\FraSoftSyncService;
use App\Services\Promotion\PromotionService;
use App\Services\Wallet\WalletService;
use App\Services\Withdrawal\WithdrawalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FinopalPlatformTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_all_five_roles_can_login_and_see_own_dashboard(): void
    {
        foreach ([
            '09121111111' => 'senior_manager',
            '09122222222' => 'development_manager',
            '09123333333' => 'sales_manager',
            '09124444444' => 'representative_referrer',
            '09125555555' => 'representative',
        ] as $mobile => $slug) {
            $login = $this->postJson('/api/auth/login', [
                'mobile' => $mobile,
                'password' => 'Password123!',
                'role_slug' => $slug,
            ])->assertOk();

            $this->withToken($login->json('token'))
                ->getJson('/api/dashboard')
                ->assertOk()
                ->assertJsonPath('role.slug', $slug);
        }
    }

    public function test_multi_role_user_can_switch_without_logout(): void
    {
        $login = $this->postJson('/api/auth/login', [
            'mobile' => '09126666666',
            'password' => 'Password123!',
            'role_slug' => 'representative',
        ])->assertOk();

        $token = $login->json('token');
        $this->withToken($token)
            ->postJson('/api/auth/switch-role', ['role_slug' => 'sales_manager'])
            ->assertOk()
            ->assertJsonPath('active_role.slug', 'sales_manager');

        $this->withToken($token)
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('role.slug', 'sales_manager');
    }

    public function test_shared_representative_and_referrer_commissions(): void
    {
        $sale = GatewaySale::query()->whereHas('gateway', fn ($q) => $q->where('external_id', 'GW-SHARE-1'))->firstOrFail();
        $repCommissions = Commission::query()
            ->where('gateway_sale_id', $sale->id)
            ->whereHas('role', fn ($q) => $q->where('slug', 'representative'))
            ->get();

        $this->assertCount(2, $repCommissions);
        foreach ($repCommissions as $row) {
            $this->assertSame('7.500', (string) $row->commission_percent);
        }

        $referrer = Commission::query()
            ->where('gateway_sale_id', $sale->id)
            ->whereHas('role', fn ($q) => $q->where('slug', 'representative_referrer'))
            ->first();

        $this->assertNotNull($referrer);
        $this->assertSame('2.000', (string) $referrer->commission_percent);
    }

    public function test_wallets_are_isolated_by_role_and_aggregate_is_read_only(): void
    {
        $user = User::query()->where('mobile', '09126666666')->firstOrFail();
        $wallets = Wallet::query()->where('user_id', $user->id)->get();
        $this->assertGreaterThanOrEqual(3, $wallets->count());
        $this->assertSame($wallets->count(), $wallets->unique('role_id')->count());

        $login = $this->postJson('/api/auth/login', [
            'mobile' => '09126666666',
            'password' => 'Password123!',
            'role_slug' => 'representative',
        ])->assertOk();

        $this->withToken($login->json('token'))
            ->getJson('/api/wallets/aggregate')
            ->assertOk()
            ->assertJsonPath('note', 'این گزارش تجمیعی فقط نمایشی است و دفاتر نقش‌ها ادغام نمی‌شوند.');
    }

    public function test_commission_idempotency_and_ledger_immutability(): void
    {
        $service = app(GatewaySaleService::class);
        $rep = User::query()->where('mobile', '09125555555')->firstOrFail();
        $first = $service->record([
            'external_id' => 'GW-IDEM-1',
            'name' => 'Idempotent',
            'amount' => 500000,
            'representative_user_id' => $rep->id,
            'idempotency_key' => 'idem-sale-1',
            'status' => 'successful',
            'merchant_code' => 'fino-idem-0001',
        ]);
        $second = $service->record([
            'external_id' => 'GW-IDEM-1',
            'name' => 'Idempotent',
            'amount' => 500000,
            'representative_user_id' => $rep->id,
            'idempotency_key' => 'idem-sale-1',
            'status' => 'successful',
            'merchant_code' => 'fino-idem-0001',
        ]);

        $this->assertSame($first->id, $second->id);

        $payload = [
            'event' => 'transaction.verified',
            'merchant_id' => 'fino-idem-0001',
            'authority' => 'FP_IDEM_1',
            'amount' => 500000,
            'profit' => 500000,
            'currency' => 'IRT',
            'status' => 'verified',
            'code' => 100,
        ];
        $this->postJson('/api/webhooks/finopal/transaction', $payload, [
            'X-Finopal-Webhook-Secret' => 'test-webhook-secret',
        ])->assertOk()->assertJsonPath('duplicate', false);
        $this->postJson('/api/webhooks/finopal/transaction', $payload, [
            'X-Finopal-Webhook-Secret' => 'test-webhook-secret',
        ])->assertOk()->assertJsonPath('duplicate', true);

        $this->assertSame(
            Commission::query()->where('gateway_sale_id', $first->id)->count(),
            Commission::query()->where('gateway_sale_id', $second->id)->count()
        );
        $this->assertSame(1, \App\Models\FinopalTransaction::query()->where('authority', 'FP_IDEM_1')->count());

        $tx = WalletTransaction::query()->first();
        $this->assertDatabaseHas('wallet_transactions', ['id' => $tx->id, 'amount' => $tx->amount]);
        $this->assertTrue(WalletTransaction::query()->whereKey($tx->id)->exists());
    }

    public function test_withdrawal_two_stage_approval(): void
    {
        $rep = User::query()->where('mobile', '09125555555')->firstOrFail();
        $role = Role::query()->where('slug', 'representative')->firstOrFail();
        $wallet = Wallet::query()->where('user_id', $rep->id)->where('role_id', $role->id)->firstOrFail();

        $withdrawal = app(WithdrawalService::class)->request($rep, $wallet, '1000.000', 'wd-1');
        $this->assertSame(WithdrawalRequest::SENIOR_MANAGER_PENDING, $withdrawal->status);

        $senior = User::query()->where('mobile', '09121111111')->firstOrFail();
        $withdrawal = app(WithdrawalService::class)->decide($senior, $withdrawal, 'approved', 'ok');
        $this->assertSame(WithdrawalRequest::SUPERUSER_PENDING, $withdrawal->status);

        $super = User::query()->where('mobile', '09120000000')->firstOrFail();
        $withdrawal = app(WithdrawalService::class)->decide($super, $withdrawal, 'approved', 'pay');
        $this->assertSame(WithdrawalRequest::COMPLETED, $withdrawal->status);
    }

    public function test_rejected_withdrawal_can_be_reopened(): void
    {
        $rep = User::query()->where('mobile', '09125555555')->firstOrFail();
        $role = Role::query()->where('slug', 'representative')->firstOrFail();
        $wallet = Wallet::query()->where('user_id', $rep->id)->where('role_id', $role->id)->firstOrFail();

        $withdrawal = app(WithdrawalService::class)->request($rep, $wallet, '800.000', 'wd-reopen-1');
        $senior = User::query()->where('mobile', '09121111111')->firstOrFail();
        $withdrawal = app(WithdrawalService::class)->decide($senior, $withdrawal, 'rejected', 'not now');
        $this->assertSame(WithdrawalRequest::REJECTED, $withdrawal->status);

        $withdrawal = app(WithdrawalService::class)->decide($senior, $withdrawal, 'approved', 'reopened');
        $this->assertSame(WithdrawalRequest::SUPERUSER_PENDING, $withdrawal->status);
    }

    public function test_senior_manager_own_withdrawal_skips_first_stage(): void
    {
        $senior = User::query()->where('mobile', '09121111111')->firstOrFail();
        $role = Role::query()->where('slug', 'senior_manager')->firstOrFail();
        $wallet = app(WalletService::class)->walletFor($senior, $role);
        app(WalletService::class)->credit($wallet, '5000.000', 'adjustment', 'credit-senior-self-wd');

        $withdrawal = app(WithdrawalService::class)->request($senior, $wallet, '500.000', 'wd-senior-self');
        $this->assertSame(WithdrawalRequest::SUPERUSER_PENDING, $withdrawal->status);

        $this->expectException(\RuntimeException::class);
        app(WithdrawalService::class)->decide($senior, $withdrawal, 'approved', 'should fail');
    }

    public function test_withdrawal_without_balance_returns_unprocessable(): void
    {
        $login = $this->postJson('/api/auth/login', [
            'mobile' => '09124444444',
            'password' => 'Password123!',
            'role_slug' => 'representative_referrer',
        ])->assertOk();

        $user = User::query()->where('mobile', '09124444444')->firstOrFail();
        $role = Role::query()->where('slug', 'representative_referrer')->firstOrFail();
        $wallet = app(WalletService::class)->walletFor($user, $role);

        $this->withToken($login->json('token'))
            ->postJson('/api/withdrawals', [
                'wallet_id' => $wallet->id,
                'amount' => '999999',
                'idempotency_key' => 'wd-no-balance',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'موجودی کافی نیست.');
    }

    public function test_tree_chat_allows_ancestors_and_denies_cross_branch(): void
    {
        $seniorLogin = $this->postJson('/api/auth/login', [
            'mobile' => '09121111111',
            'password' => 'Password123!',
            'role_slug' => 'senior_manager',
        ])->assertOk();
        $rep = User::query()->where('mobile', '09125555555')->firstOrFail();
        $outsider = User::query()->where('mobile', '09129999999')->firstOrFail();

        $this->withToken($seniorLogin->json('token'))
            ->postJson('/api/conversations', ['participant_ids' => [$rep->id]])
            ->assertCreated();

        $repLogin = $this->postJson('/api/auth/login', [
            'mobile' => '09125555555',
            'password' => 'Password123!',
            'role_slug' => 'representative',
        ])->assertOk();

        $this->withToken($repLogin->json('token'))
            ->postJson('/api/conversations', ['participant_ids' => [$outsider->id]])
            ->assertForbidden();
    }

    public function test_dynamic_permission_denies_cross_branch_override_absence(): void
    {
        $login = $this->postJson('/api/auth/login', [
            'mobile' => '09125555555',
            'password' => 'Password123!',
            'role_slug' => 'representative',
        ])->assertOk();

        $this->withToken($login->json('token'))
            ->postJson('/api/benefit-transfers', [
                'from_user_id' => 1,
                'to_user_id' => 2,
                'type' => 'all_future_benefits',
                'reason' => 'no',
            ])
            ->assertForbidden();
    }

    public function test_promotion_adds_role_without_removing_source(): void
    {
        $user = User::query()->where('mobile', '09125555555')->firstOrFail();
        $service = app(PromotionService::class);
        $request = $service->request($user, 'representative', 'sales_manager');
        $senior = User::query()->where('mobile', '09121111111')->firstOrFail();
        $service->decide($senior, $request, 'approved', 'interview ok');

        $this->assertTrue($user->fresh()->hasRole('representative'));
        $this->assertTrue($user->fresh()->hasRole('sales_manager'));
    }

    public function test_promotion_reject_requires_note_and_notifies_applicant(): void
    {
        $user = User::query()->where('mobile', '09124444444')->firstOrFail();
        $request = app(PromotionService::class)->request($user, 'representative', 'sales_manager');
        $senior = $this->postJson('/api/auth/login', [
            'mobile' => '09121111111',
            'password' => 'Password123!',
            'role_slug' => 'senior_manager',
        ])->assertOk();

        $this->withToken($senior->json('token'))
            ->postJson('/api/promotions/'.$request->id.'/decide', ['decision' => 'rejected'])
            ->assertStatus(422);

        $this->withToken($senior->json('token'))
            ->postJson('/api/promotions/'.$request->id.'/decide', [
                'decision' => 'rejected',
                'note' => 'مصاحبه قبول نشد',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'rejected');

        $rep = $this->postJson('/api/auth/login', [
            'mobile' => '09124444444',
            'password' => 'Password123!',
            'role_slug' => 'representative_referrer',
        ])->assertOk();

        $notifs = $this->withToken($rep->json('token'))->getJson('/api/notifications')->assertOk();
        $this->assertTrue(collect($notifs->json('data'))->contains(fn ($row) =>
            ($row['type'] ?? '') === 'promotion.rejected'
            && str_contains((string) ($row['body'] ?? ''), 'مصاحبه قبول نشد')
            && ($row['data']['path'] ?? '') === 'promotions'
        ));

        $dash = $this->withToken($rep->json('token'))->getJson('/api/dashboard')->assertOk();
        $this->assertSame('rejected', $dash->json('latest_rejected_promotion.status'));
        $this->assertTrue(collect($dash->json('latest_rejected_promotion.feedback'))->contains(fn ($row) =>
            ($row['decision'] ?? '') === 'rejected' && ($row['note'] ?? '') === 'مصاحبه قبول نشد'
        ));
    }

    public function test_training_progress_and_benefit_transfer(): void
    {
        $login = $this->postJson('/api/auth/login', [
            'mobile' => '09125555555',
            'password' => 'Password123!',
            'role_slug' => 'representative',
        ])->assertOk();

        $courses = $this->withToken($login->json('token'))->getJson('/api/courses')->assertOk()->json();
        $this->assertNotEmpty($courses);
        $level = $courses[0]['levels'][0];
        $this->withToken($login->json('token'))
            ->postJson('/api/courses/'.$courses[0]['id'].'/levels/'.$level['id'].'/submit', ['score' => 90])
            ->assertOk()
            ->assertJsonPath('status', 'completed');

        $from = User::query()->where('mobile', '09125555555')->firstOrFail();
        $to = User::query()->where('mobile', '09127777777')->firstOrFail();
        $senior = User::query()->where('mobile', '09121111111')->firstOrFail();
        $transfer = app(BenefitTransferService::class)->transferAll($senior, $from, $to, 'succession');
        $this->assertSame('all_future_benefits', $transfer->transfer_type);
        $this->assertFalse((bool) $from->fresh()->is_active);
        $this->postJson('/api/auth/login', [
            'mobile' => '09125555555',
            'password' => 'Password123!',
            'role_slug' => 'representative',
        ])->assertStatus(422)->assertJsonPath('errors.mobile.0', 'حساب شما مسدود است.');
    }

    public function test_frasoft_sync_is_idempotent(): void
    {
        $sync = app(FraSoftSyncService::class);
        $payload = [
            'id' => 'FS-1',
            'name' => 'نماینده فراسافت',
            'mobile' => '09120001111',
            'roles' => ['representative'],
        ];
        $first = $sync->inbound('user.upsert', $payload, 'fs-key-1');
        $second = $sync->inbound('user.upsert', $payload, 'fs-key-1');
        $this->assertSame($first->id, $second->id);
        $this->assertSame('processed', $second->status);
        $this->assertSame(1, User::query()->where('mobile', '09120001111')->count());
    }

    public function test_superuser_can_access_admin_and_others_cannot(): void
    {
        $super = $this->postJson('/api/auth/login', [
            'mobile' => '09120000000',
            'password' => 'Password123!',
            'role_slug' => 'superuser',
        ])->assertOk();

        $this->withToken($super->json('token'))->getJson('/api/superuser/stats')->assertOk();

        $rep = $this->postJson('/api/auth/login', [
            'mobile' => '09125555555',
            'password' => 'Password123!',
            'role_slug' => 'representative',
        ])->assertOk();

        $this->withToken($rep->json('token'))->getJson('/api/superuser/stats')->assertForbidden();
    }

    public function test_superuser_can_grant_and_revoke_role_permission(): void
    {
        $token = $this->postJson('/api/auth/login', [
            'mobile' => '09120000000',
            'password' => 'Password123!',
            'role_slug' => 'superuser',
        ])->json('token');

        $role = Role::query()->where('slug', 'senior_manager')->firstOrFail();
        $permission = $role->permissions()->where('slug', 'senior_manager.withdrawal.approve')->firstOrFail();

        $this->withToken($token)->postJson('/api/superuser/permissions/assign', [
            'role_id' => $role->id,
            'permission_id' => $permission->id,
            'allowed' => false,
        ])->assertOk()->assertJsonPath('allowed', false);

        $this->assertFalse($role->fresh()->permissions()->where('permissions.id', $permission->id)->exists());

        $this->withToken($token)->postJson('/api/superuser/permissions/assign', [
            'role_id' => $role->id,
            'permission_id' => $permission->id,
            'allowed' => true,
        ])->assertOk();

        $this->assertTrue($role->fresh()->permissions()->where('permissions.id', $permission->id)->wherePivot('allowed', true)->exists());
    }

    public function test_superuser_can_update_user_and_soft_delete_financial_account(): void
    {
        $token = $this->postJson('/api/auth/login', [
            'mobile' => '09120000000',
            'password' => 'Password123!',
            'role_slug' => 'superuser',
        ])->json('token');

        $user = User::query()->where('mobile', '09125555555')->firstOrFail();

        $this->withToken($token)->putJson('/api/superuser/users/'.$user->id, [
            'name' => 'نماینده ویرایش‌شده',
            'mobile' => $user->mobile,
            'email' => $user->email,
            'is_active' => true,
            'role_slugs' => ['representative'],
        ])->assertOk()->assertJsonPath('name', 'نماینده ویرایش‌شده');

        $this->withToken($token)->deleteJson('/api/superuser/users/'.$user->id)
            ->assertOk()
            ->assertJsonPath('soft_deleted', true);

        $this->assertFalse((bool) $user->fresh()->is_active);
    }

    public function test_superuser_can_bulk_deactivate_users(): void
    {
        $token = $this->postJson('/api/auth/login', [
            'mobile' => '09120000000',
            'password' => 'Password123!',
            'role_slug' => 'superuser',
        ])->json('token');

        $user = User::query()->where('mobile', '09127777777')->firstOrFail();

        $this->withToken($token)->postJson('/api/superuser/users/bulk', [
            'action' => 'deactivate',
            'ids' => [$user->id],
        ])->assertOk()->assertJsonPath('count', 1);

        $this->assertFalse((bool) $user->fresh()->is_active);

        $this->withToken($token)->postJson('/api/superuser/users/bulk', [
            'action' => 'activate',
            'ids' => [$user->id],
        ])->assertOk();

        $this->assertTrue((bool) $user->fresh()->is_active);

        $super = User::query()->where('mobile', '09120000000')->firstOrFail();
        $this->withToken($token)->postJson('/api/superuser/users/bulk', [
            'action' => 'deactivate',
            'ids' => [$super->id, $user->id],
        ])->assertOk()->assertJsonPath('count', 1);

        $this->assertTrue((bool) $super->fresh()->is_active);
        $this->assertFalse((bool) $user->fresh()->is_active);
    }

    public function test_superuser_reports_course_crud_and_one_decimal_rules(): void
    {
        $token = $this->postJson('/api/auth/login', [
            'mobile' => '09120000000',
            'password' => 'Password123!',
            'role_slug' => 'superuser',
        ])->json('token');

        $this->withToken($token)->getJson('/api/superuser/reports')
            ->assertOk()
            ->assertJsonStructure(['summary', 'operations', 'sales_over_time', 'organization', 'commissions_by_role']);

        $this->withToken($token)->getJson('/api/superuser/settings')
            ->assertOk()
            ->assertJsonStructure(['items', 'schema']);

        $roleId = Role::query()->where('slug', 'representative')->value('id');
        $course = $this->withToken($token)->postJson('/api/superuser/courses', [
            'title' => 'دوره تست واحد',
            'is_required_for_promotion' => true,
            'role_ids' => [$roleId],
            'levels' => [['title' => 'سطح ۱', 'sort_order' => 1, 'passing_score' => 70]],
        ])->assertCreated()->json();

        $this->withToken($token)->putJson('/api/superuser/courses/'.$course['id'], [
            'title' => 'دوره ویرایش‌شده',
            'is_required_for_promotion' => true,
            'role_ids' => [$roleId],
            'levels' => [[
                'id' => $course['levels'][0]['id'],
                'title' => 'سطح یک',
                'sort_order' => 1,
                'passing_score' => 80,
            ]],
        ])->assertOk()->assertJsonPath('title', 'دوره ویرایش‌شده');

        $this->withToken($token)->deleteJson('/api/superuser/courses/'.$course['id'])->assertOk();

        $rule = \App\Models\CommissionRule::query()->firstOrFail();
        $this->withToken($token)->postJson('/api/superuser/commission-rules/'.$rule->id, [
            'percent' => 20.55,
            'qualified_percent' => 12.34,
        ])->assertOk();

        $version = $rule->fresh('versions')->versions->last();
        $this->assertSame('20.6', number_format((float) $version->percent, 1, '.', ''));
        $this->assertSame('12.3', number_format((float) $version->qualified_percent, 1, '.', ''));
    }

    public function test_superuser_can_open_and_export_audit_logs(): void
    {
        $token = $this->postJson('/api/auth/login', [
            'mobile' => '09120000000',
            'password' => 'Password123!',
            'role_slug' => 'superuser',
        ])->json('token');

        $this->withToken($token)->postJson('/api/superuser/settings', [
            'key' => 'qualification_thresholds',
            'value' => [
                'representative_points' => 1000,
                'sales_manager_gateways' => 50,
                'development_manager_gateways' => 200,
            ],
        ])->assertOk();

        $list = $this->withToken($token)->getJson('/api/superuser/audits')->assertOk();
        $id = $list->json('data.0.id');
        $this->assertNotEmpty($id);

        $this->withToken($token)->getJson('/api/superuser/audits/'.$id)
            ->assertOk()
            ->assertJsonStructure(['id', 'action', 'action_label', 'entity_label', 'history', 'related']);

        $this->withToken($token)->getJson('/api/superuser/audits/export')
            ->assertOk()
            ->assertJsonStructure(['data', 'count']);
    }

    public function test_representative_can_register_gateway_with_kyc(): void
    {
        $token = $this->postJson('/api/auth/login', [
            'mobile' => '09125555555',
            'password' => 'Password123!',
            'role_slug' => 'representative',
        ])->json('token');

        $created = $this->withToken($token)->postJson('/api/gateway-sales', [
            'external_id' => 'GW-KYC-1',
            'name' => 'فروشگاه تست',
            'amount' => 1500000,
            'customer' => [
                'name' => 'علی رضایی',
                'mobile' => '09121230009',
                'national_id' => '0012345678',
                'sheba' => 'IR120170000000123456789001',
                'father_name' => 'محمد',
                'province' => 'تهران',
                'city' => 'تهران',
            ],
        ])->assertCreated()->assertJsonPath('customer.national_id', '0012345678')
            ->assertJsonPath('status', 'pending_inspection');

        $this->assertDatabaseHas('customers', [
            'national_id' => '0012345678',
            'sheba' => 'IR120170000000123456789001',
        ]);
        $this->assertSame(0, Commission::query()->where('gateway_sale_id', $created->json('id'))->count());
    }

    public function test_gateway_approval_requires_merchant_code_and_posts_no_commission(): void
    {
        $repToken = $this->postJson('/api/auth/login', [
            'mobile' => '09125555555',
            'password' => 'Password123!',
            'role_slug' => 'representative',
        ])->json('token');

        $saleId = $this->withToken($repToken)->postJson('/api/gateway-sales', [
            'external_id' => 'GW-REVIEW-1',
            'name' => 'فروشگاه بازرسی',
            'amount' => 1600000,
            'customer' => [
                'name' => 'مریم کاظمی',
                'mobile' => '09121230019',
                'national_id' => '0012345619',
                'sheba' => 'IR120170000000123456789019',
                'province' => 'قزوین',
                'city' => 'قزوین',
                'birth_place' => 'قزوین — قزوین',
            ],
        ])->assertCreated()->json('id');

        $this->assertSame(0, Commission::query()->where('gateway_sale_id', $saleId)->count());

        $this->withToken($repToken)->postJson("/api/gateway-sales/{$saleId}/inspect", [
            'decision' => 'approved',
            'merchant_code' => 'fino-review-0001',
        ])->assertForbidden();

        $seniorToken = $this->postJson('/api/auth/login', [
            'mobile' => '09121111111',
            'password' => 'Password123!',
            'role_slug' => 'senior_manager',
        ])->json('token');

        $this->withToken($seniorToken)->postJson("/api/gateway-sales/{$saleId}/inspect", [
            'decision' => 'approved',
            'note' => 'مدارک کامل است',
        ])->assertUnprocessable();

        $this->withToken($seniorToken)->postJson("/api/gateway-sales/{$saleId}/inspect", [
            'decision' => 'approved',
            'note' => 'مدارک کامل است',
            'merchant_code' => 'fino-review-0001',
        ])->assertOk()
            ->assertJsonPath('status', 'successful')
            ->assertJsonPath('gateway.merchant_code', 'fino-review-0001');

        $this->assertSame(0, Commission::query()->where('gateway_sale_id', $saleId)->count());
        $this->assertDatabaseHas('gateways', [
            'external_id' => 'GW-REVIEW-1',
            'merchant_code' => 'fino-review-0001',
            'is_active' => 1,
        ]);
    }

    public function test_finopal_transaction_webhook_posts_commissions_from_profit(): void
    {
        $this->postJson('/api/webhooks/finopal/transaction', [
            'merchant_id' => 'fino-unknown',
            'amount' => 1000,
            'profit' => 100,
            'currency' => 'IRT',
            'code' => 100,
        ], [
            'X-Finopal-Webhook-Secret' => 'test-webhook-secret',
        ])->assertNotFound();

        $this->postJson('/api/webhooks/finopal/transaction', [
            'merchant_id' => 'fino-seed-share-0001',
            'amount' => 1000,
            'profit' => 100,
        ], [
            'X-Finopal-Webhook-Secret' => 'wrong-secret',
        ])->assertUnauthorized();

        $created = $this->postJson('/api/webhooks/finopal/transaction', [
            'event' => 'transaction.verified',
            'merchant_id' => 'fino-seed-share-0001',
            'authority' => 'FP_WEBHOOK_SHARE_NEW',
            'amount' => 400000,
            'profit' => 40000,
            'currency' => 'IRT',
            'status' => 'OK',
            'code' => 100,
        ], [
            'X-Finopal-Webhook-Secret' => 'test-webhook-secret',
        ])->assertOk()
            ->assertJsonPath('duplicate', false)
            ->assertJsonPath('profit', '40000.000');

        $this->assertGreaterThan(0, $created->json('commissions'));

        $sale = GatewaySale::query()->whereHas('gateway', fn ($q) => $q->where('external_id', 'GW-SHARE-1'))->firstOrFail();
        $fromWebhook = Commission::query()
            ->where('gateway_sale_id', $sale->id)
            ->where('finopal_transaction_id', $created->json('id'))
            ->whereHas('role', fn ($q) => $q->where('slug', 'representative'))
            ->get();

        $this->assertCount(2, $fromWebhook);
        foreach ($fromWebhook as $row) {
            $this->assertSame('7.500', (string) $row->commission_percent);
            $this->assertSame('40000.000', (string) $row->base_amount);
        }

        $this->postJson('/api/webhooks/finopal/transaction', [
            'event' => 'transaction.verified',
            'merchant_id' => 'fino-seed-share-0001',
            'authority' => 'FP_WEBHOOK_SHARE_NEW',
            'amount' => 400000,
            'profit' => 40000,
            'currency' => 'IRT',
            'code' => 100,
        ], [
            'X-Finopal-Webhook-Secret' => 'test-webhook-secret',
        ])->assertOk()->assertJsonPath('duplicate', true);

        $this->assertSame(1, \App\Models\FinopalTransaction::query()->where('authority', 'FP_WEBHOOK_SHARE_NEW')->count());
    }

    public function test_only_senior_manager_can_list_or_create_benefit_transfers(): void
    {
        $rep = $this->postJson('/api/auth/login', [
            'mobile' => '09125555555',
            'password' => 'Password123!',
            'role_slug' => 'representative',
        ])->json('token');

        $this->withToken($rep)->getJson('/api/benefit-transfers')->assertForbidden();

        $senior = $this->postJson('/api/auth/login', [
            'mobile' => '09121111111',
            'password' => 'Password123!',
            'role_slug' => 'senior_manager',
        ])->json('token');

        $this->withToken($senior)->getJson('/api/benefit-transfers')->assertOk();
    }

    public function test_senior_and_superuser_can_manage_courses_with_mixed_content(): void
    {
        $roleId = Role::query()->where('slug', 'representative')->value('id');
        $senior = $this->postJson('/api/auth/login', [
            'mobile' => '09121111111',
            'password' => 'Password123!',
            'role_slug' => 'senior_manager',
        ])->json('token');

        $course = $this->withToken($senior)->postJson('/api/manage/courses', [
            'title' => 'دوره مدیر ارشد',
            'is_required_for_promotion' => true,
            'role_ids' => [$roleId],
            'levels' => [[
                'title' => 'ویدیو و متن',
                'sort_order' => 1,
                'passing_score' => 70,
                'content_type' => 'video',
                'content_url' => 'https://example.com/lesson.mp4',
                'content_body' => 'شرح متنی کنار ویدیو',
            ]],
        ])->assertCreated()->json();

        $this->assertSame('video', $course['levels'][0]['content_type']);

        $sales = $this->postJson('/api/auth/login', [
            'mobile' => '09123333333',
            'password' => 'Password123!',
            'role_slug' => 'sales_manager',
        ])->json('token');
        $this->withToken($sales)->getJson('/api/manage/courses')->assertForbidden();
    }

    public function test_required_training_blocks_promotion_eligibility(): void
    {
        $outsider = User::query()->where('mobile', '09129999999')->firstOrFail();
        $eval = app(PromotionService::class)->evaluate($outsider, 'sales_manager');
        $training = collect($eval)->firstWhere('code', 'required_training');
        $this->assertNotEmpty($training);
        $this->assertFalse($training['passed']);
    }

    public function test_chat_accepts_common_file_attachments(): void
    {
        Storage::fake('public');
        $senior = $this->postJson('/api/auth/login', [
            'mobile' => '09121111111',
            'password' => 'Password123!',
            'role_slug' => 'senior_manager',
        ]);
        $dev = User::query()->where('mobile', '09122222222')->firstOrFail();
        $conv = $this->withToken($senior->json('token'))
            ->postJson('/api/conversations', ['participant_ids' => [$dev->id]])
            ->assertCreated()
            ->json('id');

        $this->withToken($senior->json('token'))
            ->post('/api/conversations/'.$conv.'/messages', [
                'body' => 'فایل پیوست',
                'file' => UploadedFile::fake()->create('guide.pdf', 120, 'application/pdf'),
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('message_type', 'file');
    }

    public function test_login_payload_includes_page_permissions(): void
    {
        $login = $this->postJson('/api/auth/login', [
            'mobile' => '09123333333',
            'password' => 'Password123!',
            'role_slug' => 'sales_manager',
        ])->assertOk();

        $perms = $login->json('user.permissions');
        $this->assertContains('page.dashboard', $perms);
        $this->assertNotContains('page.transfers', $perms);
        $this->assertNotContains('page.courses_manage', $perms);
    }

    public function test_senior_can_block_downline_but_not_outsiders(): void
    {
        $senior = $this->postJson('/api/auth/login', [
            'mobile' => '09121111111',
            'password' => 'Password123!',
            'role_slug' => 'senior_manager',
        ])->json('token');

        $rep = User::query()->where('mobile', '09125555555')->firstOrFail();
        $this->withToken($senior)->postJson('/api/users/'.$rep->id.'/block', ['reason' => 'تست مسدودسازی'])
            ->assertOk()
            ->assertJsonPath('is_active', false);

        $this->postJson('/api/auth/login', [
            'mobile' => '09125555555',
            'password' => 'Password123!',
            'role_slug' => 'representative',
        ])->assertStatus(422)->assertJsonPath('errors.mobile.0', 'حساب شما مسدود است.');

        $this->withToken($senior)->postJson('/api/users/'.$rep->id.'/unblock')
            ->assertOk()
            ->assertJsonPath('is_active', true);

        $stranger = User::factory()->create(['is_active' => true]);
        $this->withToken($senior)->postJson('/api/users/'.$stranger->id.'/block')->assertForbidden();

        $sales = $this->postJson('/api/auth/login', [
            'mobile' => '09123333333',
            'password' => 'Password123!',
            'role_slug' => 'sales_manager',
        ])->json('token');
        $this->withToken($sales)->postJson('/api/users/'.$rep->id.'/block')->assertForbidden();
    }

    public function test_superuser_can_block_any_user_except_self(): void
    {
        $login = $this->postJson('/api/auth/login', [
            'mobile' => '09120000000',
            'password' => 'Password123!',
            'role_slug' => 'superuser',
        ]);
        $token = $login->json('token');
        $super = User::query()->where('mobile', '09120000000')->firstOrFail();
        $stranger = User::factory()->create(['is_active' => true]);

        $this->withToken($token)->postJson('/api/users/'.$stranger->id.'/block')
            ->assertOk()
            ->assertJsonPath('is_active', false);

        $this->withToken($token)->postJson('/api/users/'.$super->id.'/block')->assertForbidden();
    }

    public function test_geo_locations_lists_imported_states_and_cities(): void
    {
        $token = $this->postJson('/api/auth/login', [
            'mobile' => '09125555555',
            'password' => 'Password123!',
            'role_slug' => 'representative',
        ])->json('token');

        $this->withToken($token)->getJson('/api/geo/locations')
            ->assertOk()
            ->assertJsonPath('states.0.title', 'آذربایجان شرقی')
            ->assertJsonCount(31, 'states');

        $cities = $this->withToken($token)->getJson('/api/geo/locations')->json('cities');
        $this->assertGreaterThan(1000, count($cities));
        $this->assertTrue(collect($cities)->contains(fn ($city) => str_contains((string) $city['title'], 'قزوین')));
    }

    public function test_conversation_list_includes_unread_count_per_chat(): void
    {
        $senior = $this->postJson('/api/auth/login', [
            'mobile' => '09121111111',
            'password' => 'Password123!',
            'role_slug' => 'senior_manager',
        ])->assertOk();
        $dev = User::query()->where('mobile', '09122222222')->firstOrFail();

        $conv = $this->withToken($senior->json('token'))
            ->postJson('/api/conversations', ['participant_ids' => [$dev->id]])
            ->assertCreated()
            ->json('id');

        $this->withToken($senior->json('token'))
            ->postJson('/api/conversations/'.$conv.'/messages', ['body' => 'پیام نخوانده'])
            ->assertCreated();

        $devLogin = $this->postJson('/api/auth/login', [
            'mobile' => '09122222222',
            'password' => 'Password123!',
            'role_slug' => 'development_manager',
        ])->assertOk();

        $this->withToken($devLogin->json('token'))
            ->getJson('/api/conversations')
            ->assertOk()
            ->assertJsonPath('unread', 1)
            ->assertJsonPath('conversations.0.unread_count', 1);

        $this->withToken($devLogin->json('token'))
            ->postJson('/api/conversations/'.$conv.'/read')
            ->assertOk();

        $this->withToken($devLogin->json('token'))
            ->getJson('/api/conversations')
            ->assertOk()
            ->assertJsonPath('unread', 0)
            ->assertJsonPath('conversations.0.unread_count', 0);
    }
}
