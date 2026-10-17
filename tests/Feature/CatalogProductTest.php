<?php

namespace Tests\Feature;

use App\Models\CatalogProduct;
use App\Models\FinopalTransaction;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogProductTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config(['finopal.webhook_secret' => 'test-webhook-secret']);
    }

    public function test_superuser_manages_products_and_webhook_uses_them(): void
    {
        $rep = User::query()->where('mobile', '09125555555')->firstOrFail();
        $rep->forceFill(['national_id' => '0044332211'])->save();
        $super = User::query()->where('mobile', '09120000000')->firstOrFail();
        $roleId = Role::query()->where('slug', 'superuser')->value('id');
        [, $token] = $super->createApiToken($roleId);
        [, $repToken] = $rep->createApiToken(Role::query()->where('slug', 'representative')->value('id'));

        $this->withToken($repToken)->postJson('/api/superuser/products', [
            'type' => 'qa_demo',
            'label' => 'نباید',
        ])->assertForbidden();

        $this->withToken($token)->getJson('/api/superuser/products')
            ->assertOk()
            ->assertJsonFragment(['type' => 'ghalebazar', 'label' => 'قالب بازار']);

        $this->withToken($token)->postJson('/api/superuser/products', [
            'type' => 'qa_demo',
            'label' => 'محصول آزمایشی',
            'requires_merchant' => false,
            'signup_url' => 'https://example.com/join',
            'default_code' => 'QA-DEMO',
            'is_active' => true,
        ])->assertCreated()->assertJsonPath('type', 'qa_demo');

        $this->withToken($repToken)->getJson('/api/catalog/products')
            ->assertOk()
            ->assertJsonFragment(['type' => 'qa_demo', 'label' => 'محصول آزمایشی']);

        $this->postJson('/api/webhooks/finopal/transaction', [
            'product_type' => 'not_defined_yet',
            'owner_national_id' => '0044332211',
            'amount' => 1000,
            'profit' => 100,
        ], ['X-Finopal-Webhook-Secret' => 'test-webhook-secret'])->assertStatus(422);

        $this->postJson('/api/webhooks/finopal/transaction', [
            'product_type' => 'qa_demo',
            'amount' => 1000,
            'profit' => 100,
        ], ['X-Finopal-Webhook-Secret' => 'test-webhook-secret'])->assertStatus(422);

        $res = $this->postJson('/api/webhooks/finopal/transaction', [
            'event' => 'transaction.verified',
            'product_type' => 'qa_demo',
            'owner_national_id' => '0044332211',
            'authority' => 'FP_QA_DEMO_1',
            'amount' => 1500000,
            'profit' => 150000,
            'currency' => 'IRT',
            'status' => 'OK',
            'code' => 100,
        ], ['X-Finopal-Webhook-Secret' => 'test-webhook-secret'])->assertOk();

        $this->assertSame('qa_demo', FinopalTransaction::query()->findOrFail($res->json('id'))->product_type);
        $this->assertSame('QA-DEMO', FinopalTransaction::query()->findOrFail($res->json('id'))->product_code);

        $system = CatalogProduct::query()->where('type', 'gateway_profit')->firstOrFail();
        $this->withToken($token)->deleteJson('/api/superuser/products/'.$system->id)->assertStatus(422);

        $created = CatalogProduct::query()->where('type', 'qa_demo')->firstOrFail();
        $this->withToken($token)->deleteJson('/api/superuser/products/'.$created->id)->assertStatus(422);

        $created->forceFill(['is_active' => false])->save();
        $this->postJson('/api/webhooks/finopal/transaction', [
            'product_type' => 'qa_demo',
            'owner_national_id' => '0044332211',
            'authority' => 'FP_QA_DEMO_2',
            'amount' => 1000,
            'profit' => 100,
            'currency' => 'IRT',
            'code' => 100,
        ], ['X-Finopal-Webhook-Secret' => 'test-webhook-secret'])->assertStatus(422);
    }
}
