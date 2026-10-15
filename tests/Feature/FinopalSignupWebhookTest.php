<?php

namespace Tests\Feature;

use App\Models\Gateway;
use App\Models\GatewaySale;
use App\Models\OrganizationNode;
use App\Models\ReferralCode;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Services\Organization\OrganizationTreeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinopalSignupWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_signup_and_gateway_webhooks_are_separate_steps(): void
    {
        $repRole = $this->role('representative', 'نماینده', 5);
        $smRole = $this->role('sales_manager', 'مدیر فروش', 3);
        $this->role('representative_referrer', 'نماینده معرف', 4);

        $manager = User::factory()->create(['name' => 'مدیر فروش', 'mobile' => '09120000001']);
        $referrer = User::factory()->create(['name' => 'معرف', 'mobile' => '09120000002']);
        $this->attachRole($manager, $smRole);
        $this->attachRole($referrer, $repRole);

        $tree = app(OrganizationTreeService::class);
        $smNode = $tree->attach($manager, $smRole, null, now()->toDateString());
        $tree->attach($referrer, $repRole, $smNode, now()->toDateString());

        ReferralCode::query()->create([
            'user_id' => $referrer->id,
            'code' => 'REF-SIGNUP-1',
            'source' => 'finopal',
            'is_active' => true,
        ]);

        $userPayload = [
            'event' => 'user.registered',
            'referral_code' => 'REF-SIGNUP-1',
            'user' => [
                'name' => 'کاربر فینوپال',
                'mobile' => '09123334455',
                'national_id' => '1234567890',
            ],
        ];
        $gatewayPayload = [
            'event' => 'gateway.registered',
            'idempotency_key' => 'fp-gateway-1',
            'user' => [
                'mobile' => '09123334455',
                'national_id' => '1234567890',
            ],
            'gateway' => [
                'external_id' => 'fp-gw-1',
                'merchant_code' => 'MRC-100',
                'name' => 'فروشگاه نمونه',
                'amount' => 1500000,
            ],
        ];

        $this->assertSame(
            'https://finopal.ir/login?finonet_ref=REF-SIGNUP-1',
            \App\Services\Integration\Finopal\FinopalSignupWebhookService::signupUrl('REF-SIGNUP-1'),
        );

        $this->postJson('/api/webhooks/finopal/gateway', $gatewayPayload, [
            'X-Finopal-Webhook-Secret' => 'test-webhook-secret',
        ])->assertStatus(422)
            ->assertJsonPath('errors.user.0', 'این کاربر در سازمان فروش ثبت نشده است. ابتدا وب‌هوک ثبت کاربر را ارسال کنید.');

        $this->assertSame(0, Gateway::query()->count());

        $signup = $this->postJson('/api/webhooks/finopal/signup', $userPayload, [
            'X-Finopal-Webhook-Secret' => 'test-webhook-secret',
        ])->assertOk();
        $signup->assertJsonPath('user_created', true);
        $signup->assertJsonMissingPath('gateway_id');

        $newbie = User::query()->where('national_id', '1234567890')->firstOrFail();
        $this->assertTrue($newbie->hasRole('representative'));
        $node = OrganizationNode::query()->where('user_id', $newbie->id)->where('is_active', true)->firstOrFail();
        $this->assertSame($smNode->id, (int) $node->parent_node_id);
        $this->assertSame(0, GatewaySale::query()->count());

        $updated = $userPayload;
        $updated['user']['name'] = 'کاربر ویرایش‌شده';
        $updated['user']['birth_date'] = '1991-04-03';
        $updated['user']['email'] = 'finopal-user@example.com';
        $again = $this->postJson('/api/webhooks/finopal/signup', $updated, [
            'X-Finopal-Webhook-Secret' => 'test-webhook-secret',
        ])->assertOk();
        $again->assertJsonPath('user_created', false)
            ->assertJsonPath('user_updated', true)
            ->assertJsonPath('user_skipped', false)
            ->assertJsonPath('user_id', $newbie->id);
        $newbie->refresh();
        $this->assertSame('کاربر ویرایش‌شده', $newbie->name);
        $this->assertSame('1991-04-03', $newbie->birth_date?->toDateString());
        $this->assertSame('finopal-user@example.com', $newbie->email);
        $this->assertSame($smNode->id, (int) $node->fresh()->parent_node_id);
        $this->assertSame(1, User::query()->where('national_id', '1234567890')->count());

        $gateway = $this->postJson('/api/webhooks/finopal/gateway', $gatewayPayload, [
            'X-Finopal-Webhook-Secret' => 'test-webhook-secret',
        ])->assertOk();
        $gateway->assertJsonPath('gateway_created', true)
            ->assertJsonPath('user_id', $newbie->id);

        $sale = GatewaySale::query()->findOrFail($gateway->json('gateway_sale_id'));
        $this->assertSame('successful', $sale->status);
        $this->assertSame($newbie->id, (int) $sale->representatives()->value('user_id'));
        $this->assertTrue($sale->managers()->where('user_id', $manager->id)->exists());

        $this->postJson('/api/webhooks/finopal/gateway', $gatewayPayload, [
            'X-Finopal-Webhook-Secret' => 'test-webhook-secret',
        ])->assertOk()
            ->assertJsonPath('gateway_created', false);
        $this->assertSame(1, Gateway::query()->where('merchant_code', 'MRC-100')->count());

        $collision = $gatewayPayload;
        $collision['gateway']['external_id'] = 'fp-gw-other';
        $collision['gateway']['merchant_code'] = 'MRC-OTHER';
        $this->postJson('/api/webhooks/finopal/gateway', $collision, [
            'X-Finopal-Webhook-Secret' => 'test-webhook-secret',
        ])->assertStatus(422)
            ->assertJsonPath('errors.idempotency_key.0', 'این کلید یکتا قبلاً برای درگاه دیگری ثبت شده است. برای درگاه جدید یک idempotency_key تازه بفرستید.');
        $this->assertSame(0, Gateway::query()->where('external_id', 'fp-gw-other')->count());

        $collision['idempotency_key'] = 'fp-gateway-2';
        $this->postJson('/api/webhooks/finopal/gateway', $collision, [
            'X-Finopal-Webhook-Secret' => 'test-webhook-secret',
        ])->assertOk()
            ->assertJsonPath('gateway_created', true);
        $this->assertSame(1, Gateway::query()->where('external_id', 'fp-gw-other')->count());

        $this->postJson('/api/webhooks/finopal/signup', $userPayload, [
            'X-Finopal-Webhook-Secret' => 'wrong',
        ])->assertStatus(401);

        $spaced = $userPayload;
        $spaced['referral_code'] = '  https://finopal.ir/login?finonet_ref=REF-SIGNUP-1  ';
        $spaced['user']['national_id'] = '1098765432';
        $spaced['user']['mobile'] = '09124445566';
        $this->postJson('/api/webhooks/finopal/signup', $spaced, [
            'X-Finopal-Webhook-Secret' => 'test-webhook-secret',
        ])->assertOk()
            ->assertJsonPath('user_created', true);
    }

    private function role(string $slug, string $name, int $level): Role
    {
        return Role::query()->create([
            'name' => $name,
            'slug' => $slug,
            'hierarchy_level' => $level,
            'is_organizational' => true,
            'is_active' => true,
        ]);
    }

    private function attachRole(User $user, Role $role): void
    {
        UserRole::query()->create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'effective_from' => now()->toDateString(),
            'is_primary' => true,
            'is_active' => true,
        ]);
    }
}
