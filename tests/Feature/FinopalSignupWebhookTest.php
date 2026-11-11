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

        // کاربر تکراری نباید موفق شود و نباید جابه‌جا/آپدیت شود
        $updated = $userPayload;
        $updated['user']['name'] = 'کاربر ویرایش‌شده';
        $updated['user']['birth_date'] = '1991-04-03';
        $updated['user']['email'] = 'finopal-user@example.com';
        $again = $this->postJson('/api/webhooks/finopal/signup', $updated, [
            'X-Finopal-Webhook-Secret' => 'test-webhook-secret',
        ])->assertStatus(422);
        $again->assertJsonPath('errors.user.0', 'این کاربر قبلاً ثبت شده است.');
        $newbie->refresh();
        $this->assertSame('کاربر فینوپال', $newbie->name);
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

    public function test_signup_rejects_already_registered_without_moving_upline(): void
    {
        $repRole = $this->role('representative', 'نماینده', 5);
        $smRole = $this->role('sales_manager', 'مدیر فروش', 3);
        $this->role('representative_referrer', 'نماینده معرف', 4);

        $manager = User::factory()->create(['name' => 'مدیر', 'mobile' => '09120000101']);
        $referrerA = User::factory()->create(['name' => 'معرف الف', 'mobile' => '09120000102']);
        $referrerB = User::factory()->create(['name' => 'معرف ب', 'mobile' => '09120000103']);
        $this->attachRole($manager, $smRole);
        $this->attachRole($referrerA, $repRole);
        $this->attachRole($referrerB, $repRole);

        $tree = app(OrganizationTreeService::class);
        $smNode = $tree->attach($manager, $smRole, null, now()->toDateString());
        $tree->attach($referrerA, $repRole, $smNode, now()->toDateString());
        $tree->attach($referrerB, $repRole, $smNode, now()->toDateString());

        ReferralCode::query()->create(['user_id' => $referrerA->id, 'code' => 'REF-A', 'source' => 'finopal', 'is_active' => true]);
        ReferralCode::query()->create(['user_id' => $referrerB->id, 'code' => 'REF-B', 'source' => 'finopal', 'is_active' => true]);

        $payload = [
            'referral_code' => 'REF-A',
            'user' => [
                'name' => 'کاربر الف',
                'mobile' => '09128887766',
                'national_id' => '5566778899',
            ],
        ];

        $this->postJson('/api/webhooks/finopal/signup', $payload, [
            'X-Finopal-Webhook-Secret' => 'test-webhook-secret',
        ])->assertOk()->assertJsonPath('success', true);

        $user = User::query()->where('national_id', '5566778899')->firstOrFail();
        $node = OrganizationNode::query()->where('user_id', $user->id)->where('is_active', true)->firstOrFail();
        $parentBefore = (int) $node->parent_node_id;

        $retryUnderB = $payload;
        $retryUnderB['referral_code'] = 'REF-B';
        $retryUnderB['user']['name'] = 'نباید عوض شود';

        $this->postJson('/api/webhooks/finopal/signup', $retryUnderB, [
            'X-Finopal-Webhook-Secret' => 'test-webhook-secret',
        ])->assertStatus(422)
            ->assertJsonPath('message', 'این کاربر قبلاً ثبت شده است.')
            ->assertJsonPath('errors.user.0', 'این کاربر قبلاً ثبت شده است.');

        $user->refresh();
        $node->refresh();
        $this->assertSame('کاربر الف', $user->name);
        $this->assertSame($parentBefore, (int) $node->parent_node_id);
        $this->assertSame(1, User::query()->where('national_id', '5566778899')->count());
    }

    public function test_signup_converts_persian_digits_on_mobile_and_national_id(): void
    {
        config(['finopal.webhook_secret' => 'test-webhook-secret']);

        $repRole = $this->role('representative', 'نماینده', 5);
        $smRole = $this->role('sales_manager', 'مدیر فروش', 3);
        $this->role('representative_referrer', 'نماینده معرف', 4);

        $manager = User::factory()->create(['name' => 'مدیر', 'mobile' => '09120000201']);
        $referrer = User::factory()->create(['name' => 'معرف', 'mobile' => '09120000202']);
        $this->attachRole($manager, $smRole);
        $this->attachRole($referrer, $repRole);

        $tree = app(OrganizationTreeService::class);
        $smNode = $tree->attach($manager, $smRole, null, now()->toDateString());
        $tree->attach($referrer, $repRole, $smNode, now()->toDateString());

        ReferralCode::query()->create([
            'user_id' => $referrer->id,
            'code' => 'REF-FA-DIGITS',
            'source' => 'finopal',
            'is_active' => true,
        ]);

        $this->postJson('/api/webhooks/finopal/signup', [
            'referral_code' => 'REF-FA-DIGITS',
            'user' => [
                'name' => 'کاربر ارقام فارسی',
                'mobile' => '۰۹۱۲۷۸۴۴۹۴۴',
                'national_id' => '۰۰۱۲۳۴۵۶۷۸',
            ],
        ], [
            'X-Finopal-Webhook-Secret' => 'test-webhook-secret',
        ])->assertOk()->assertJsonPath('user_created', true);

        $user = User::query()->where('mobile', '09127844944')->first();
        $this->assertNotNull($user);
        $this->assertSame('09127844944', $user->mobile);
        $this->assertSame('0012345678', $user->national_id);
        $this->assertSame(0, User::query()->where('mobile', '۰۹۱۲۷۸۴۴۹۴۴')->count());
    }

    public function test_signup_links_point_at_each_product_site(): void
    {
        $links = collect(\App\Services\Integration\Finopal\FinopalSignupWebhookService::signupLinks('REF-SIGNUP-1'))
            ->keyBy('product_type');

        $this->assertSame(
            'https://finopal.ir/login?finonet_ref=REF-SIGNUP-1',
            $links['gateway_profit']['url']
        );
        $this->assertStringContainsString('قالب', $links['ghalebazar']['label']);
        $this->assertSame(
            'https://ghalebazar.ir/account/profile?finonet_ref=REF-SIGNUP-1',
            $links['ghalebazar']['url']
        );
        $this->assertArrayNotHasKey('ticketing', $links->all());
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
