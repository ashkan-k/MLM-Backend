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
 * از آبان ۱۴۰۵ محاسبات شمسی است؛ رکورد میلادی ۲۰۲۶-۱۰ دست‌نخورده می‌ماند
 * و سود قبل از cutover داخل پاداش آبان دوباره حساب نمی‌شود.
 */
class JalaliMonthlyBonusScenarioTest extends TestCase
{
    use RefreshDatabase;

    private User $senior;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config(['finopal.webhook_secret' => 'test-webhook-secret']);

        $lab = app(EmptyOrgLabService::class);
        $lab->purgeNonSuperusers();
        $lab->seedEmptyOrg();

        $this->senior = User::query()->where('mobile', '09121111111')->firstOrFail();
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
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_aban_uses_jalali_without_touching_frozen_october(): void
    {
        $ref = ReferralCode::query()->where('user_id', $this->senior->id)->where('is_active', true)->value('code');
        $this->assertNotEmpty($ref);
        $dmRole = Role::query()->where('slug', 'development_manager')->firstOrFail();

        // رکورد تاریخی حسابداری (مثل پروداکشن بعد از restore)
        $oct = Commission::query()->create([
            'user_id' => $this->senior->id,
            'role_id' => $dmRole->id,
            'gateway_sale_id' => null,
            'finopal_transaction_id' => null,
            'rule_version_id' => null,
            'base_amount' => '1840800.000',
            'commission_percent' => '1.500',
            'commission_amount' => '27612.000',
            'status' => 'posted',
            'idempotency_key' => "monthly-bonus:{$this->senior->id}:{$dmRole->id}:2026-10",
            'metadata' => ['type' => 'monthly_bonus', 'month' => '2026-10', 'profit_sum' => '1840800.000'],
        ]);

        Carbon::setTestNow(Carbon::parse('2026-10-21 15:00:00', JalaliDate::TIMEZONE));
        $rep = $this->registerRep('نماینده مهر', '09120100901', $ref);
        $sale = app(GatewaySaleService::class)->record([
            'external_id' => 'GW-JALALI-MEHR-1',
            'name' => 'درگاه مهر',
            'amount' => 1500000,
            'representatives' => [['user_id' => $rep->id, 'share_percent' => '100.000']],
            'customer' => ['name' => 'مشتری مهر', 'mobile' => '09121230901'],
            'idempotency_key' => 'jalali-mehr-sale',
            'status' => 'successful',
            'merchant_code' => 'fino-jalali-mehr-0001',
        ])->load('gateway');

        // درگاه مهر برای پاداش آبان هم واجد شرایط دائمی بماند (مثل پروداکشن)
        UserBonusDefault::query()->firstOrCreate([
            'user_id' => $this->senior->id,
            'role_id' => $dmRole->id,
        ]);
        GatewayBonusEligibility::query()->firstOrCreate([
            'user_id' => $this->senior->id,
            'role_id' => $dmRole->id,
            'gateway_sale_id' => $sale->id,
        ], ['qualified_month' => '2026-10']);

        // سود ۱ آبان — قبل از cutover؛ فقط داخل ۲۷۶۱۲ تاریخی می‌ماند
        Carbon::setTestNow(Carbon::parse('2026-10-23 20:12:00', JalaliDate::TIMEZONE));
        $this->assertSame('1405-08', JalaliDate::businessMonthKey());
        $this->postTx($sale->gateway->merchant_code, 'FP_JALALI_ABAN_EARLY', 6_000);

        $previewEarly = app(MonthlyBonusService::class)->preview($this->senior, 'development_manager', now());
        $this->assertSame('1405-08', $previewEarly['month']);
        $this->assertEqualsWithDelta(0.0, (float) $previewEarly['bonus_amount'], 0.01);

        // بعد از cutover: سود جدید فقط در آبان شمسی
        Carbon::setTestNow(Carbon::parse('2026-10-24 12:00:00', JalaliDate::TIMEZONE));
        $this->postTx($sale->gateway->merchant_code, 'FP_JALALI_ABAN_NEW', 10_000);

        $preview = app(MonthlyBonusService::class)->preview($this->senior, 'development_manager', now());
        $this->assertSame('1405-08', $preview['month']);
        $this->assertEqualsWithDelta(150.0, (float) $preview['bonus_amount'], 0.01); // 10000 × 1.5%

        app(MonthlyBonusService::class)->refresh($this->senior, 'development_manager', now());

        $aban = Commission::query()
            ->where('idempotency_key', "monthly-bonus:{$this->senior->id}:{$dmRole->id}:1405-08")
            ->where('status', 'posted')
            ->first();
        $this->assertNotNull($aban);
        $this->assertEqualsWithDelta(150.0, (float) $aban->commission_amount, 0.01);

        $oct->refresh();
        $this->assertSame('posted', $oct->status);
        $this->assertEqualsWithDelta(27612.0, (float) $oct->commission_amount, 0.01);
    }

    public function test_schedule_settles_previous_jalali_month_on_first_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-23 01:15:00', JalaliDate::TIMEZONE));
        $this->assertSame(1, JalaliDate::parts()[2]);
        $this->assertSame('2026-10', JalaliDate::businessPreviousMonthKey());

        Carbon::setTestNow(Carbon::parse('2026-11-22 01:15:00', JalaliDate::TIMEZONE)); // ۱ آذر
        $this->assertSame(1, JalaliDate::parts()[2]);
        $this->assertSame('1405-08', JalaliDate::businessPreviousMonthKey());
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

    private function postTx(string $merchant, string $authority, int $profit): void
    {
        $this->postJson('/api/webhooks/finopal/transaction', [
            'event' => 'transaction.verified',
            'merchant_id' => $merchant,
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
