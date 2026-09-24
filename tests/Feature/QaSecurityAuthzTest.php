<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * QA Phases 16–19: authz / IDOR / webhook forgery smoke.
 */
class QaSecurityAuthzTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function login(string $mobile, string $role): string
    {
        return $this->postJson('/api/auth/login', [
            'mobile' => $mobile,
            'password' => 'Password123!',
            'role_slug' => $role,
        ])->assertOk()->json('token');
    }

    public function test_unauthenticated_protected_routes_reject(): void
    {
        $this->getJson('/api/dashboard')->assertUnauthorized();
        $this->getJson('/api/wallets')->assertUnauthorized();
        $this->getJson('/api/commissions')->assertUnauthorized();
        $this->getJson('/api/superuser/users')->assertUnauthorized();
    }

    public function test_representative_cannot_access_superuser_apis(): void
    {
        $token = $this->login('09125555555', 'representative');
        $this->withToken($token)->getJson('/api/superuser/users')->assertForbidden();
        $this->withToken($token)->getJson('/api/superuser/stats')->assertForbidden();
    }

    public function test_wallets_endpoint_only_returns_own_user_wallets(): void
    {
        $token = $this->login('09125555555', 'representative');
        $me = User::query()->where('mobile', '09125555555')->firstOrFail();
        $other = User::query()->where('mobile', '09129999999')->firstOrFail();

        $payload = $this->withToken($token)->getJson('/api/wallets')->assertOk()->json();
        foreach ($payload as $row) {
            $this->assertSame($me->id, $row['user_id'] ?? null);
            $this->assertNotSame($other->id, $row['user_id'] ?? null);
        }
    }

    public function test_points_adjust_forbidden_for_senior(): void
    {
        $token = $this->login('09121111111', 'senior_manager');
        $this->withToken($token)->postJson('/api/points/adjust', [
            'user_id' => User::query()->where('mobile', '09125555555')->value('id'),
            'role_slug' => 'representative',
            'delta_points' => 100,
            'month' => now()->format('Y-m'),
            'note' => 'qa',
        ])->assertForbidden();
    }

    public function test_forged_webhook_secret_rejected(): void
    {
        $this->postJson('/api/webhooks/finopal/transaction', [
            'merchant_id' => 'anything',
            'amount' => 1,
            'profit' => 1,
        ], ['X-Finopal-Webhook-Secret' => 'forged'])->assertUnauthorized();
    }

    public function test_registration_rejects_missing_referral(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'QA NoRef',
            'mobile' => '09130000001',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])->assertStatus(422);
    }

    public function test_registration_rejects_invalid_referral_code(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'QA BadRef',
            'mobile' => '09130000002',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'referral_code' => 'NOT-A-REAL-CODE-XYZ',
        ])->assertStatus(422);
    }

    public function test_mass_assignment_style_extra_fields_on_login_ignored(): void
    {
        $res = $this->postJson('/api/auth/login', [
            'mobile' => '09125555555',
            'password' => 'Password123!',
            'role_slug' => 'representative',
            'is_superuser' => true,
            'roles' => ['superuser'],
        ])->assertOk();

        $this->assertNotSame('superuser', $res->json('active_role.slug'));
        $this->withToken($res->json('token'))
            ->getJson('/api/superuser/users')
            ->assertForbidden();
    }
}
