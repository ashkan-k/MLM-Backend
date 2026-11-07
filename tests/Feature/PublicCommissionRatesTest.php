<?php

namespace Tests\Feature;

use App\Models\CommissionRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicCommissionRatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_public_commission_rates_match_seeded_rules(): void
    {
        $res = $this->getJson('/api/public/commission-rates')->assertOk();
        $roles = collect($res->json('roles'))->keyBy('slug');

        $this->assertSame(15.0, (float) $roles['representative']['base_percent']);
        $this->assertSame(5.0, (float) $roles['representative']['bonus_delta_percent']);
        $this->assertSame(20.0, (float) $roles['representative']['qualified_percent']);
        $this->assertSame(1000, $roles['representative']['points_threshold']);

        $this->assertSame(2.0, (float) $roles['representative_referrer']['base_percent']);
        $this->assertSame(0.0, (float) $roles['representative_referrer']['bonus_delta_percent']);
        $this->assertNull($roles['representative_referrer']['points_threshold']);

        $this->assertSame(6.0, (float) $roles['sales_manager']['base_percent']);
        $this->assertSame(2.0, (float) $roles['sales_manager']['bonus_delta_percent']);

        $this->assertSame(4.5, (float) $roles['development_manager']['base_percent']);
        $this->assertSame(1.5, (float) $roles['development_manager']['bonus_delta_percent']);

        $this->assertSame(4.0, (float) $roles['senior_manager']['base_percent']);
        $this->assertSame(0.0, (float) $roles['senior_manager']['bonus_delta_percent']);
        $this->assertSame(100, (int) $res->json('points_per_gateway'));
    }

    public function test_public_rates_follow_superuser_rule_version_update(): void
    {
        $rule = CommissionRule::query()->where('code', 'representative')->firstOrFail();
        $this->postJson('/api/superuser/commission-rules/'.$rule->id, [
            'percent' => 12,
            'qualified_percent' => 18,
        ])->assertUnauthorized();

        // Direct version create mirrors panel update (auth tested elsewhere)
        $rule->versions()->create([
            'version' => ((int) $rule->versions()->max('version')) + 1,
            'percent' => 12,
            'qualified_percent' => 18,
            'effective_from' => now()->subMinute(),
        ]);

        $res = $this->getJson('/api/public/commission-rates')->assertOk();
        $rep = collect($res->json('roles'))->firstWhere('slug', 'representative');
        $this->assertSame(12.0, (float) $rep['base_percent']);
        $this->assertSame(6.0, (float) $rep['bonus_delta_percent']);
        $this->assertSame(18.0, (float) $rep['qualified_percent']);
    }
}
