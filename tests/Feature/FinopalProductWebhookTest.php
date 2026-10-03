<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\FinopalTransaction;
use App\Models\ProductSale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinopalProductWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config(['finopal.webhook_secret' => 'test-webhook-secret']);
    }

    public function test_ticketing_product_webhook_splits_commissions_by_owner_national_id(): void
    {
        $rep = User::query()->where('mobile', '09125555555')->firstOrFail();
        $rep->forceFill(['national_id' => '0099887766'])->save();

        $res = $this->postJson('/api/webhooks/finopal/transaction', [
            'event' => 'transaction.verified',
            'product_type' => 'ticketing',
            'product_code' => 'FINOPAL-TICKETING',
            'title' => 'خرید سیستم تیکتینگ',
            'owner_national_id' => '0099887766',
            'external_sale_id' => 'TICKET-ORDER-1001',
            'authority' => 'FP_TICKET_1001',
            'amount' => 5000000,
            'profit' => 500000,
            'currency' => 'IRT',
            'status' => 'OK',
            'code' => 100,
        ], [
            'X-Finopal-Webhook-Secret' => 'test-webhook-secret',
        ])->assertOk()
            ->assertJsonPath('duplicate', false)
            ->assertJsonPath('profit', '500000.000');

        $this->assertGreaterThan(0, $res->json('commissions'));

        $tx = FinopalTransaction::query()->findOrFail($res->json('id'));
        $this->assertSame('ticketing', $tx->product_type);
        $this->assertSame('FINOPAL-TICKETING', $tx->product_code);
        $this->assertNull($tx->gateway_id);
        $this->assertNotNull($tx->product_sale_id);

        $sale = ProductSale::query()->findOrFail($tx->product_sale_id);
        $this->assertSame('successful', $sale->status);
        $this->assertTrue($sale->representatives()->where('user_id', $rep->id)->exists());

        $repCommission = Commission::query()
            ->where('product_sale_id', $sale->id)
            ->where('finopal_transaction_id', $tx->id)
            ->where('user_id', $rep->id)
            ->whereHas('role', fn ($q) => $q->where('slug', 'representative'))
            ->first();

        $this->assertNotNull($repCommission);
        $this->assertSame('500000.000', (string) $repCommission->base_amount);
        $this->assertSame('15.000', (string) $repCommission->commission_percent);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $rep->id,
            'type' => 'product.transaction',
        ]);

        $this->postJson('/api/webhooks/finopal/transaction', [
            'event' => 'transaction.verified',
            'product_type' => 'ticketing',
            'owner_national_id' => '0099887766',
            'authority' => 'FP_TICKET_1001',
            'amount' => 5000000,
            'profit' => 500000,
            'currency' => 'IRT',
            'code' => 100,
        ], [
            'X-Finopal-Webhook-Secret' => 'test-webhook-secret',
        ])->assertOk()->assertJsonPath('duplicate', true);
    }

    public function test_gateway_profit_still_requires_merchant_id(): void
    {
        $this->postJson('/api/webhooks/finopal/transaction', [
            'product_type' => 'gateway_profit',
            'amount' => 1000,
            'profit' => 100,
            'currency' => 'IRT',
        ], [
            'X-Finopal-Webhook-Secret' => 'test-webhook-secret',
        ])->assertStatus(422);
    }

    public function test_non_gateway_product_requires_owner(): void
    {
        $this->postJson('/api/webhooks/finopal/transaction', [
            'product_type' => 'ticketing',
            'amount' => 1000,
            'profit' => 100,
            'currency' => 'IRT',
        ], [
            'X-Finopal-Webhook-Secret' => 'test-webhook-secret',
        ])->assertStatus(422);
    }
}
