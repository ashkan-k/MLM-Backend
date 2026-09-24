<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\GatewaySale;
use App\Models\Notification;
use App\Models\PromotionRequest;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WithdrawalRequest;
use App\Services\Withdrawal\WithdrawalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * IDOR matrix: foreign path-IDs must not leak data / mutate resources.
 */
class QaIdorMatrixTest extends TestCase
{
    use RefreshDatabase;

    private string $repToken;

    private string $outsiderToken;

    private User $rep;

    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->rep = User::query()->where('mobile', '09125555555')->firstOrFail();
        $this->outsider = User::query()->where('mobile', '09129999999')->firstOrFail();

        $this->repToken = $this->login('09125555555', 'representative');
        $this->outsiderToken = $this->login('09129999999', 'representative');
    }

    private function login(string $mobile, string $role): string
    {
        return $this->postJson('/api/auth/login', [
            'mobile' => $mobile,
            'password' => 'Password123!',
            'role_slug' => $role,
        ])->assertOk()->json('token');
    }

    public function test_idor_gateway_sale_show_denied_for_unrelated_rep(): void
    {
        $sale = GatewaySale::query()->whereHas('gateway', fn ($q) => $q->where('external_id', 'GW-SOLO-1'))->firstOrFail();

        $this->withToken($this->outsiderToken)
            ->getJson('/api/gateway-sales/'.$sale->id)
            ->assertForbidden();

        $this->withToken($this->repToken)
            ->getJson('/api/gateway-sales/'.$sale->id)
            ->assertOk();
    }

    public function test_idor_gateway_inspect_denied_for_rep(): void
    {
        $sale = GatewaySale::query()->firstOrFail();

        $this->withToken($this->repToken)
            ->postJson('/api/gateway-sales/'.$sale->id.'/inspect', [
                'decision' => 'approved',
                'merchant_code' => 'idor-hack',
            ])
            ->assertForbidden();
    }

    public function test_idor_gateway_parties_denied_for_rep(): void
    {
        $sale = GatewaySale::query()->firstOrFail();

        $this->withToken($this->repToken)
            ->postJson('/api/gateway-sales/'.$sale->id.'/parties', [
                'representatives' => [
                    ['user_id' => $this->rep->id, 'share_percent' => 100],
                ],
            ])
            ->assertForbidden();
    }

    public function test_idor_withdrawal_cancel_denied_for_other_user(): void
    {
        $role = Role::query()->where('slug', 'representative')->firstOrFail();
        $wallet = Wallet::query()->where('user_id', $this->rep->id)->where('role_id', $role->id)->firstOrFail();

        $wd = app(WithdrawalService::class)->request(
            $this->rep,
            '10.000',
            'active_role',
            $wallet,
            $role,
            'idor-wd-'.uniqid()
        );

        $this->withToken($this->outsiderToken)
            ->postJson('/api/withdrawals/'.$wd->id.'/cancel')
            ->assertStatus(422);

        $this->assertDatabaseHas('withdrawal_requests', [
            'id' => $wd->id,
            'user_id' => $this->rep->id,
        ]);
        $this->assertNotSame(WithdrawalRequest::CANCELLED, $wd->fresh()->status);
    }

    public function test_idor_withdrawal_decide_denied_for_outsider_rep(): void
    {
        $role = Role::query()->where('slug', 'representative')->firstOrFail();
        $wallet = Wallet::query()->where('user_id', $this->rep->id)->where('role_id', $role->id)->firstOrFail();
        $wd = app(WithdrawalService::class)->request($this->rep, '10.000', 'active_role', $wallet, $role, 'idor-wd2-'.uniqid());

        $this->withToken($this->outsiderToken)
            ->postJson('/api/withdrawals/'.$wd->id.'/decide', [
                'decision' => 'approved',
            ])
            ->assertStatus(422);
    }

    public function test_idor_promotion_show_denied_cross_user(): void
    {
        $promo = PromotionRequest::query()->create([
            'user_id' => $this->rep->id,
            'from_role_id' => Role::query()->where('slug', 'representative')->value('id'),
            'target_role_id' => Role::query()->where('slug', 'sales_manager')->value('id'),
            'status' => 'pending',
            'submitted_at' => now(),
        ]);

        $this->withToken($this->outsiderToken)
            ->getJson('/api/promotions/'.$promo->id)
            ->assertForbidden();

        $this->withToken($this->repToken)
            ->getJson('/api/promotions/'.$promo->id)
            ->assertOk();
    }

    public function test_idor_notification_read_denied(): void
    {
        $n = Notification::query()->create([
            'user_id' => $this->rep->id,
            'type' => 'qa.idor',
            'title' => 'IDOR',
            'body' => 'private',
            'data' => [],
        ]);

        $this->withToken($this->outsiderToken)
            ->postJson('/api/notifications/'.$n->id.'/read')
            ->assertForbidden();
    }

    public function test_idor_conversation_messages_denied(): void
    {
        $conv = Conversation::query()->create([
            'type' => 'direct',
            'title' => 'IDOR chat',
            'created_by' => $this->rep->id,
        ]);
        ConversationParticipant::query()->create([
            'conversation_id' => $conv->id,
            'user_id' => $this->rep->id,
            'joined_at' => now(),
        ]);

        $this->withToken($this->outsiderToken)
            ->getJson('/api/conversations/'.$conv->id.'/messages')
            ->assertForbidden();
    }

    public function test_idor_block_user_denied_for_unrelated_rep(): void
    {
        $other = User::query()->where('mobile', '09124444444')->firstOrFail();

        $this->withToken($this->outsiderToken)
            ->postJson('/api/users/'.$other->id.'/block', ['reason' => 'hack'])
            ->assertForbidden();
    }

    public function test_idor_gateway_shares_denied_for_non_senior(): void
    {
        $this->withToken($this->repToken)
            ->getJson('/api/users/'.$this->outsider->id.'/gateway-shares')
            ->assertForbidden();
    }

    public function test_idor_superuser_user_mutation_denied(): void
    {
        $this->withToken($this->repToken)
            ->putJson('/api/superuser/users/'.$this->outsider->id, [
                'name' => 'Hacked',
            ])
            ->assertForbidden();

        $this->withToken($this->repToken)
            ->deleteJson('/api/superuser/users/'.$this->outsider->id)
            ->assertForbidden();
    }

    public function test_idor_superuser_audit_and_rules_denied(): void
    {
        // Unauthorized: 403 preferred; 404 acceptable if binding runs before authz on missing id
        $audit = $this->withToken($this->repToken)->getJson('/api/superuser/audits/1');
        $this->assertContains($audit->status(), [403, 404]);

        $this->withToken($this->repToken)
            ->postJson('/api/superuser/commission-rules/1', ['percent' => 99])
            ->assertForbidden();

        $this->withToken($this->repToken)
            ->getJson('/api/superuser/users')
            ->assertForbidden();
    }

    public function test_idor_unauthenticated_path_ids_rejected(): void
    {
        $sale = GatewaySale::query()->firstOrFail();
        $this->getJson('/api/gateway-sales/'.$sale->id)->assertUnauthorized();

        $role = Role::query()->where('slug', 'representative')->firstOrFail();
        $wallet = Wallet::query()->where('user_id', $this->rep->id)->where('role_id', $role->id)->firstOrFail();
        $wd = app(WithdrawalService::class)->request($this->rep, '10.000', 'active_role', $wallet, $role, 'idor-unauth-'.uniqid());

        $this->postJson('/api/withdrawals/'.$wd->id.'/cancel')->assertUnauthorized();
        $this->getJson('/api/superuser/users')->assertUnauthorized();
        $this->getJson('/api/superuser/stats')->assertUnauthorized();
    }
}
