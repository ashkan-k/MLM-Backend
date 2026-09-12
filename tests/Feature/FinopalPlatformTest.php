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
use App\Services\Withdrawal\WithdrawalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        ]);
        $second = $service->record([
            'external_id' => 'GW-IDEM-1',
            'name' => 'Idempotent',
            'amount' => 500000,
            'representative_user_id' => $rep->id,
            'idempotency_key' => 'idem-sale-1',
        ]);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(
            Commission::query()->where('gateway_sale_id', $first->id)->count(),
            Commission::query()->where('gateway_sale_id', $second->id)->count()
        );

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
}
