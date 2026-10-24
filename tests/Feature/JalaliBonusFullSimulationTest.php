<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\GatewayBonusEligibility;
use App\Models\ReferralCode;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\UserBonusDefault;
use App\Services\Commission\MonthlyBonusService;
use App\Services\Gateway\GatewaySaleService;
use App\Services\Organization\EmptyOrgLabService;
use App\Support\JalaliDate;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * شبیه‌سازی کامل مرز ماه شمسی، cutover آبان، و حفظ تاریخچه میلادی.
 */
class JalaliBonusFullSimulationTest extends TestCase
{
    use RefreshDatabase;

    private User $senior;

    private Role $dmRole;

    private string $merchant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config(['finopal.webhook_secret' => 'test-webhook-secret']);

        $lab = app(EmptyOrgLabService::class);
        $lab->purgeNonSuperusers();
        $lab->seedEmptyOrg();

        $this->senior = User::query()->where('mobile', '09121111111')->firstOrFail();
        $this->dmRole = Role::query()->where('slug', 'development_manager')->firstOrFail();

        SystemSetting::query()->updateOrCreate(
            ['key' => 'qualification_thresholds'],
            [
                'value' => [
                    'representative_points' => 100,
                    'sales_manager_points' => 100,
                    'development_manager_points' => 100,
                ],
                'value_type' => 'json',
                'is_public' => true,
            ]
        );

        UserBonusDefault::query()->firstOrCreate([
            'user_id' => $this->senior->id,
            'role_id' => $this->dmRole->id,
        ]);

        $ref = ReferralCode::query()->where('user_id', $this->senior->id)->where('is_active', true)->value('code');
        Carbon::setTestNow(Carbon::parse('2026-10-21 12:00:00', JalaliDate::TIMEZONE));
        $rep = $this->registerRep('نماینده شبیه‌سازی', '09120100888', $ref);
        $sale = app(GatewaySaleService::class)->record([
            'external_id' => 'GW-SIM-1',
            'name' => 'درگاه شبیه‌سازی',
            'amount' => 1500000,
            'representatives' => [['user_id' => $rep->id, 'share_percent' => '100.000']],
            'customer' => ['name' => 'مشتری', 'mobile' => '09121230888'],
            'idempotency_key' => 'sim-sale-1',
            'status' => 'successful',
            'merchant_code' => 'fino-sim-0001',
        ])->load('gateway');

        $this->merchant = $sale->gateway->merchant_code;
        GatewayBonusEligibility::query()->firstOrCreate([
            'user_id' => $this->senior->id,
            'role_id' => $this->dmRole->id,
            'gateway_sale_id' => $sale->id,
        ], ['qualified_month' => '2026-10']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_month_keys_and_full_jalali_bounds(): void
    {
        // مهر → هنوز کلید کسب‌وکار میلادی اکتبر
        Carbon::setTestNow(Carbon::parse('2026-10-21 10:00:00', JalaliDate::TIMEZONE));
        $this->assertSame('1405-07', JalaliDate::monthKey());
        $this->assertSame('2026-10', JalaliDate::businessMonthKey());

        // ۱ آبان
        Carbon::setTestNow(Carbon::parse('2026-10-23 00:30:00', JalaliDate::TIMEZONE));
        $this->assertSame('1405-08', JalaliDate::businessMonthKey());
        [$abanStart, $abanEnd, $abanKey] = JalaliDate::businessMonthBounds(now(), null, false);
        $this->assertSame('1405-08', $abanKey);
        $this->assertSame('2026-10-22 20:30:00', $abanStart->copy()->timezone('UTC')->format('Y-m-d H:i:s')); // ۰۰:۰۰ تهران
        $this->assertSame(1, JalaliDate::parts($abanStart->copy()->timezone(JalaliDate::TIMEZONE))[2]);

        // با cutover سود آبان از ۲۴ اکتبر
        [$abanProfitStart] = JalaliDate::businessMonthBounds(now(), '1405-08', true);
        $this->assertTrue($abanProfitStart->equalTo(JalaliDate::bonusJalaliProfitCutover()));

        // ۱ آذر ۱۴۰۵ ≈ ۲۲ نوامبر ۲۰۲۶ — ماه کامل بدون cutover
        Carbon::setTestNow(Carbon::parse('2026-11-22 12:00:00', JalaliDate::TIMEZONE));
        $this->assertSame('1405-09', JalaliDate::businessMonthKey());
        [$azarStart, $azarEnd, $azarKey] = JalaliDate::businessMonthBounds(now(), null, true);
        $this->assertSame('1405-09', $azarKey);
        $this->assertSame(1, JalaliDate::parts($azarStart->copy()->timezone(JalaliDate::TIMEZONE))[2]);
        $this->assertSame(9, JalaliDate::parts($azarStart->copy()->timezone(JalaliDate::TIMEZONE))[1]);
        // cutover فقط برای آبان است
        $this->assertFalse($azarStart->equalTo(JalaliDate::bonusJalaliProfitCutover()));

        // مرز: آخر آبان vs اول آذر
        Carbon::setTestNow(Carbon::parse('2026-11-21 23:59:00', JalaliDate::TIMEZONE));
        $this->assertSame('1405-08', JalaliDate::businessMonthKey());
        Carbon::setTestNow(Carbon::parse('2026-11-22 00:01:00', JalaliDate::TIMEZONE));
        $this->assertSame('1405-09', JalaliDate::businessMonthKey());
        $this->assertSame('1405-08', JalaliDate::businessPreviousMonthKey());
    }

    public function test_frozen_october_and_aban_cutover_math(): void
    {
        $oct = $this->seedFrozenOctober();

        // ۱ آبان — سود ۶۰۰۰ داخل تاریخچه؛ آبان شمسی هنوز ۰
        Carbon::setTestNow(Carbon::parse('2026-10-23 20:12:00', JalaliDate::TIMEZONE));
        $this->postTx('FP_SIM_ABAN_EARLY', 6_000);
        $early = app(MonthlyBonusService::class)->preview($this->senior, 'development_manager', now());
        $this->assertSame('1405-08', $early['month']);
        $this->assertEqualsWithDelta(0.0, (float) $early['bonus_amount'], 0.01);

        // بعد از cutover — فقط سود جدید
        Carbon::setTestNow(Carbon::parse('2026-10-24 15:00:00', JalaliDate::TIMEZONE));
        $this->postTx('FP_SIM_ABAN_A', 20_000); // ×۱٫۵٪ = ۳۰۰
        $this->postTx('FP_SIM_ABAN_B', 40_000); // ×۱٫۵٪ = ۶۰۰ → جمع ۹۰۰

        $mid = app(MonthlyBonusService::class)->preview($this->senior, 'development_manager', now());
        $this->assertSame('1405-08', $mid['month']);
        $this->assertEqualsWithDelta(900.0, (float) $mid['bonus_amount'], 0.01);
        $this->assertEqualsWithDelta(60000.0, (float) $mid['profit_sum'], 0.01);

        app(MonthlyBonusService::class)->refresh($this->senior, 'development_manager', now());
        $aban = $this->bonusRow('1405-08');
        $this->assertNotNull($aban);
        $this->assertSame('posted', $aban->status);
        $this->assertEqualsWithDelta(900.0, (float) $aban->commission_amount, 0.01);

        $oct->refresh();
        $this->assertSame('posted', $oct->status);
        $this->assertEqualsWithDelta(27612.0, (float) $oct->commission_amount, 0.01);

        // اواخر آبان — جمع افزایش
        Carbon::setTestNow(Carbon::parse('2026-11-20 18:00:00', JalaliDate::TIMEZONE));
        $this->assertSame('1405-08', JalaliDate::businessMonthKey());
        $this->postTx('FP_SIM_ABAN_LATE', 10_000); // +۱۵۰ → ۱۰۵۰
        app(MonthlyBonusService::class)->refresh($this->senior, 'development_manager', now());
        $aban->refresh();
        $this->assertEqualsWithDelta(1050.0, (float) $aban->commission_amount, 0.01);
        $oct->refresh();
        $this->assertEqualsWithDelta(27612.0, (float) $oct->commission_amount, 0.01);
    }

    public function test_azar_is_full_jalali_month_separate_from_aban(): void
    {
        $oct = $this->seedFrozenOctober();

        Carbon::setTestNow(Carbon::parse('2026-10-25 12:00:00', JalaliDate::TIMEZONE));
        $this->postTx('FP_SIM_ABAN_ONLY', 8_000); // ۱۲۰ آبان
        app(MonthlyBonusService::class)->refresh($this->senior, 'development_manager', now());
        $this->assertEqualsWithDelta(120.0, (float) $this->bonusRow('1405-08')->commission_amount, 0.01);

        // ۱ آذر — سطل جدید؛ آبان قفل می‌ماند
        Carbon::setTestNow(Carbon::parse('2026-11-22 10:00:00', JalaliDate::TIMEZONE));
        $this->assertSame('1405-09', JalaliDate::businessMonthKey());
        $this->postTx('FP_SIM_AZAR_1', 50_000); // ۷۵۰
        $this->postTx('FP_SIM_AZAR_2', 50_000); // ۷۵۰ → ۱۵۰۰

        $preview = app(MonthlyBonusService::class)->preview($this->senior, 'development_manager', now());
        $this->assertSame('1405-09', $preview['month']);
        $this->assertEqualsWithDelta(1500.0, (float) $preview['bonus_amount'], 0.01);
        $this->assertEqualsWithDelta(100000.0, (float) $preview['profit_sum'], 0.01);

        app(MonthlyBonusService::class)->refresh($this->senior, 'development_manager', now());
        $azar = $this->bonusRow('1405-09');
        $this->assertNotNull($azar);
        $this->assertEqualsWithDelta(1500.0, (float) $azar->commission_amount, 0.01);

        $aban = $this->bonusRow('1405-08');
        $this->assertSame('posted', $aban->status);
        $this->assertEqualsWithDelta(120.0, (float) $aban->commission_amount, 0.01);

        $oct->refresh();
        $this->assertSame('posted', $oct->status);
        $this->assertEqualsWithDelta(27612.0, (float) $oct->commission_amount, 0.01);

        // settle ماه قبل در ۱ آذر
        $this->artisan('finopal:pay-monthly-bonuses', ['--month' => '1405-08', '--force' => true])->assertSuccessful();
        $aban->refresh();
        $this->assertEqualsWithDelta(120.0, (float) $aban->commission_amount, 0.01);
    }

    public function test_dey_boundary_after_azar(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-12-21 23:30:00', JalaliDate::TIMEZONE)); // حدود آخر آذر
        $this->assertSame('1405-09', JalaliDate::monthKey());

        Carbon::setTestNow(Carbon::parse('2026-12-22 00:30:00', JalaliDate::TIMEZONE)); // ۱ دی تقریباً
        $this->assertSame('1405-10', JalaliDate::businessMonthKey());
        $this->assertSame('1405-09', JalaliDate::businessPreviousMonthKey());

        [$start, $end, $key] = JalaliDate::businessMonthBounds(now(), null, true);
        $this->assertSame('1405-10', $key);
        $partsStart = JalaliDate::parts($start->copy()->timezone(JalaliDate::TIMEZONE));
        $this->assertSame([1405, 10, 1], $partsStart);
    }

    private function seedFrozenOctober(): Commission
    {
        return Commission::query()->create([
            'user_id' => $this->senior->id,
            'role_id' => $this->dmRole->id,
            'gateway_sale_id' => null,
            'finopal_transaction_id' => null,
            'rule_version_id' => null,
            'base_amount' => '1840800.000',
            'commission_percent' => '1.500',
            'commission_amount' => '27612.000',
            'status' => 'posted',
            'idempotency_key' => "monthly-bonus:{$this->senior->id}:{$this->dmRole->id}:2026-10",
            'metadata' => ['type' => 'monthly_bonus', 'month' => '2026-10', 'profit_sum' => '1840800.000'],
        ]);
    }

    private function bonusRow(string $month): ?Commission
    {
        return Commission::query()
            ->where('idempotency_key', "monthly-bonus:{$this->senior->id}:{$this->dmRole->id}:{$month}")
            ->first();
    }

    private function registerRep(string $name, string $mobile, string $ref): User
    {
        $this->postJson('/api/auth/register', [
            'name' => $name,
            'mobile' => $mobile,
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'referral_code' => $ref,
        ])->assertCreated();

        return User::query()->where('mobile', $mobile)->firstOrFail();
    }

    private function postTx(string $authority, int $profit): void
    {
        $this->postJson('/api/webhooks/finopal/transaction', [
            'event' => 'transaction.verified',
            'merchant_id' => $this->merchant,
            'authority' => $authority,
            'amount' => max($profit * 10, $profit),
            'profit' => $profit,
            'currency' => 'IRT',
            'status' => 'OK',
            'code' => 100,
        ], [
            'X-Finopal-Webhook-Secret' => 'test-webhook-secret',
        ])->assertOk();
    }
}
