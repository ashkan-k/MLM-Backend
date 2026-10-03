<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\FinopalTransaction;
use App\Models\ProductSale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ProductOrientedDemoSeedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config([
            'finopal.webhook_secret' => 'test-webhook-secret',
            'finopal.product_oriented' => true,
        ]);
    }

    public function test_product_demo_seeder_creates_gateway_and_product_commissions(): void
    {
        $code = Artisan::call('finopal:seed-product-demo');
        $this->assertSame(0, $code);

        $this->assertGreaterThan(0, ProductSale::query()->count());
        $this->assertGreaterThan(0, FinopalTransaction::query()->where('product_type', 'ticketing')->count());
        $this->assertGreaterThan(0, FinopalTransaction::query()->where('product_type', 'gateway_profit')->count());
        $this->assertGreaterThan(0, FinopalTransaction::query()->where('product_type', 'subscription')->count());
        $this->assertGreaterThan(10, Commission::query()->count());

        $this->assertTrue(
            Commission::query()->whereNotNull('product_sale_id')->exists(),
            'expected product-sale commissions'
        );
        $this->assertTrue(
            Commission::query()->whereNotNull('gateway_sale_id')->exists(),
            'expected gateway-sale commissions'
        );

        $repWallet = \App\Models\Wallet::query()
            ->whereHas('user', fn ($q) => $q->where('mobile', '09125555555'))
            ->where('kind', 'role')
            ->first();
        $this->assertNotNull($repWallet);
        $this->assertGreaterThan(0, (float) $repWallet->balance, 'seed should credit representative wallet');

        // Reset نباید کیف را صفر کند در حالی که پورسانت محصول هنوز posted است
        Artisan::call('finopal:seed-webhook-demo');
        $afterGatewayReset = (float) $repWallet->fresh()->balance;
        $this->assertGreaterThan(0, $afterGatewayReset, 'gateway demo reset must not wipe product commission wallet credits');
    }
}
