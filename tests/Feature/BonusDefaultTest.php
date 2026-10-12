<?php

namespace Tests\Feature;

use App\Models\Gateway;
use App\Models\GatewayBonusEligibility;
use App\Models\GatewayRepresentative;
use App\Models\GatewaySale;
use App\Models\Role;
use App\Models\User;
use App\Models\UserBonusDefault;
use App\Services\Commission\PointsMonitorService;
use App\Services\Commission\QualificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BonusDefaultTest extends TestCase
{
    use RefreshDatabase;

    public function test_bonus_default_locks_past_and_future_gateways_without_threshold(): void
    {
        $superRole = Role::query()->create([
            'name' => 'سوپریوزر',
            'slug' => 'superuser',
            'hierarchy_level' => 0,
            'is_organizational' => false,
            'is_active' => true,
        ]);
        $repRole = Role::query()->create([
            'name' => 'نماینده',
            'slug' => 'representative',
            'hierarchy_level' => 4,
            'is_organizational' => true,
            'is_active' => true,
        ]);

        $super = User::factory()->create(['name' => 'سوپر']);
        $rep = User::factory()->create(['name' => 'نماینده']);
        $super->roles()->attach($superRole->id, [
            'effective_from' => now()->toDateString(),
            'is_primary' => true,
            'is_active' => true,
        ]);
        $rep->roles()->attach($repRole->id, [
            'effective_from' => now()->toDateString(),
            'is_primary' => true,
            'is_active' => true,
        ]);

        $past = $this->saleFor($rep, 'past-gw', now()->subMonths(2));

        $points = app(PointsMonitorService::class);
        $on = $points->setBonusDefault($super, $rep->id, 'representative', true);

        $this->assertTrue($on['enabled']);
        $listed = collect($points->listBonusDefaults($super, $rep->name))
            ->firstWhere('id', $rep->id);
        $this->assertNotNull($listed);
        $this->assertTrue($listed['roles'][0]['bonus_default']);
        $this->assertSame(1, $on['locked_gateways']);
        $this->assertTrue(UserBonusDefault::query()->where('user_id', $rep->id)->where('role_id', $repRole->id)->exists());
        $this->assertTrue(
            GatewayBonusEligibility::query()
                ->where('user_id', $rep->id)
                ->where('role_id', $repRole->id)
                ->where('gateway_sale_id', $past->id)
                ->exists()
        );

        $future = $this->saleFor($rep, 'future-gw', now());
        $locked = app(QualificationService::class)->syncPermanentEligibilities('representative', $rep, $repRole->id, now());
        $this->assertSame(1, $locked);
        $this->assertTrue(
            GatewayBonusEligibility::query()
                ->where('user_id', $rep->id)
                ->where('gateway_sale_id', $future->id)
                ->exists()
        );

        $points->setBonusDefault($super, $rep->id, 'representative', false);
        $this->assertFalse(UserBonusDefault::query()->where('user_id', $rep->id)->exists());

        $afterOff = $this->saleFor($rep, 'after-off-gw', now());
        $lockedAfterOff = app(QualificationService::class)->syncPermanentEligibilities('representative', $rep, $repRole->id, now());
        $this->assertSame(0, $lockedAfterOff);
        $this->assertFalse(
            GatewayBonusEligibility::query()->where('gateway_sale_id', $afterOff->id)->exists()
        );
        $this->assertTrue(
            GatewayBonusEligibility::query()->where('gateway_sale_id', $past->id)->exists()
        );
    }

    private function saleFor(User $rep, string $externalId, \DateTimeInterface $soldAt): GatewaySale
    {
        $gateway = Gateway::query()->create([
            'external_id' => $externalId,
            'name' => $externalId,
            'sale_amount' => 1000000,
        ]);

        $sale = GatewaySale::query()->create([
            'gateway_id' => $gateway->id,
            'amount' => 1000000,
            'full_sales_points' => 100,
            'status' => 'successful',
            'sold_at' => $soldAt,
            'idempotency_key' => $externalId,
        ]);

        GatewayRepresentative::query()->create([
            'gateway_sale_id' => $sale->id,
            'user_id' => $rep->id,
            'share_percent' => '100.000',
            'sales_points' => 100,
        ]);

        return $sale;
    }
}
