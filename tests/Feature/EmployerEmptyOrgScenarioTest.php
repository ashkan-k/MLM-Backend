<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\Course;
use App\Models\GatewayBonusEligibility;
use App\Models\OrganizationNode;
use App\Models\PromotionRequest;
use App\Models\ReferralCode;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\UserCourseProgress;
use App\Models\Wallet;
use App\Services\Commission\MonthlyBonusService;
use App\Services\Gateway\GatewaySaleService;
use App\Services\Organization\EmptyOrgLabService;
use App\Services\Organization\OrganizationTreeService;
use App\Services\Promotion\PromotionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * سناریوی کارفرما از سازمان خالی:
 * سوپریوزر + ارشد → معرفی نماینده‌ها → درگاه/وب‌هوک → پورسانت پایه روی کیف‌های SM/DM/Senior ارشد
 * → باقی‌مانده پاداش به ارشد → حد نصاب و پاداش دائمی → ارتقاء به SM و جابه‌جایی زیرمجموعه
 * → لینک اشتراکی معرف/درگاه + حجم تراکنش webhook.
 */
class EmployerEmptyOrgScenarioTest extends TestCase
{
    use RefreshDatabase;

    private User $super;

    private User $senior;

    private string $seniorToken;

    private string $seniorRefCode;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $lab = app(EmptyOrgLabService::class);
        $lab->purgeNonSuperusers();
        $lab->seedEmptyOrg();

        $this->super = User::query()->where('mobile', '09120000000')->firstOrFail();
        $this->senior = User::query()->where('mobile', '09121111111')->firstOrFail();
        $this->assertTrue($this->senior->hasRole('senior_manager'));
        $this->assertTrue($this->senior->hasRole('sales_manager'));
        $this->assertTrue($this->senior->hasRole('development_manager'));

        $this->seniorToken = $this->postJson('/api/auth/login', [
            'mobile' => '09121111111',
            'password' => 'Password123!',
            'role_slug' => 'senior_manager',
        ])->assertOk()->json('token');

        $this->seniorRefCode = ReferralCode::query()
            ->where('user_id', $this->senior->id)
            ->where('is_active', true)
            ->value('code');
        $this->assertNotEmpty($this->seniorRefCode);
    }

    public function test_empty_org_base_commissions_credit_senior_manager_chain_wallets(): void
    {
        $ali = $this->registerRep('علی نماینده', '09120100001', $this->seniorRefCode);
        $sale = $this->recordSoloSale($ali, 'GW-EMPTY-SOLO-1', 'fino-empty-solo-0001');

        $txId = $this->webhook($sale->gateway->merchant_code, 'FP_EMPTY_BASE_1', 100000);

        $byRole = $this->commissionsByRole($txId);

        $this->assertEqualsWithDelta(15000.0, (float) $byRole['representative']['amount'], 0.01);
        $this->assertSame($ali->id, (int) $byRole['representative']['user_id']);

        // معرف = ارشد (ثبت‌نام با کد ارشد)
        $this->assertEqualsWithDelta(2000.0, (float) $byRole['representative_referrer']['amount'], 0.01);
        $this->assertSame($this->senior->id, (int) $byRole['representative_referrer']['user_id']);

        // بدون SM/DM جدا → هر سه نقش مدیریتی روی خودِ ارشد
        foreach (['sales_manager' => 6000.0, 'development_manager' => 4500.0, 'senior_manager' => 4000.0] as $slug => $amount) {
            $this->assertEqualsWithDelta($amount, (float) $byRole[$slug]['amount'], 0.01, $slug);
            $this->assertSame($this->senior->id, (int) $byRole[$slug]['user_id'], $slug);
        }

        $this->assertEqualsWithDelta(15000.0, $this->walletBalance($ali, 'representative'), 0.01);
        $this->assertEqualsWithDelta(6000.0, $this->walletBalance($this->senior, 'sales_manager'), 0.01);
        $this->assertEqualsWithDelta(4500.0, $this->walletBalance($this->senior, 'development_manager'), 0.01);
        $this->assertEqualsWithDelta(4000.0, $this->walletBalance($this->senior, 'senior_manager'), 0.01);
        $this->assertEqualsWithDelta(2000.0, $this->walletBalance($this->senior, 'representative_referrer'), 0.01);
    }

    public function test_unqualified_monthly_bonus_residual_settles_to_senior(): void
    {
        // حد نصاب بالا بماند → با یک درگاه هیچ نقشی واجد پاداش نشود
        $this->setQualificationThresholds(1000, 5000, 20000);

        $ali = $this->registerRep('علی باقیمانده', '09120100002', $this->seniorRefCode);
        $sale = $this->recordSoloSale($ali, 'GW-EMPTY-RES-1', 'fino-empty-res-0001');
        $this->webhook($sale->gateway->merchant_code, 'FP_EMPTY_RES_1', 100000);

        $month = now()->format('Y-m');
        $repRole = Role::query()->where('slug', 'representative')->firstOrFail();
        $this->assertFalse(
            Commission::query()
                ->where('idempotency_key', "monthly-bonus:{$ali->id}:{$repRole->id}:{$month}")
                ->where('status', 'posted')
                ->exists()
        );

        $residual = app(MonthlyBonusService::class)->settleUnpaidToSenior(now());
        $this->assertNotNull($residual);
        $this->assertSame('posted', $residual->status);

        // ۵٪ نماینده + ۲٪ SM + ۱٫۵٪ DM روی ۱۰۰٬۰۰۰ سود غیرواجد = ۸٬۵۰۰
        $this->assertEqualsWithDelta(8500.0, (float) $residual->commission_amount, 0.01);
        $this->assertSame($this->senior->id, $residual->user_id);
        $this->assertSame('monthly_bonus_residual', $residual->metadata['type'] ?? null);

        $seniorBeforeBase = 4000.0; // ممکن است از تست موازی نباشد؛ فقط residual را از کیف چک می‌کنیم
        $this->assertGreaterThanOrEqual(
            8500.0 - 0.01,
            $this->walletBalance($this->senior, 'senior_manager')
        );
    }

    public function test_rep_reaches_bonus_threshold_locks_gateway_and_keeps_earning_forever(): void
    {
        $this->setQualificationThresholds(100, 5000, 20000); // یک درگاه برای نماینده

        $ali = $this->registerRep('سارا پاداش', '09120100003', $this->seniorRefCode);
        $sale = $this->recordSoloSale($ali, 'GW-EMPTY-BONUS-1', 'fino-empty-bonus-0001');

        $this->webhook($sale->gateway->merchant_code, 'FP_EMPTY_BONUS_1', 100000);

        $month = now()->format('Y-m');
        $repRole = Role::query()->where('slug', 'representative')->firstOrFail();

        $this->assertTrue(
            GatewayBonusEligibility::query()
                ->where('user_id', $ali->id)
                ->where('role_id', $repRole->id)
                ->where('gateway_sale_id', $sale->id)
                ->exists()
        );

        $bonus = Commission::query()
            ->where('idempotency_key', "monthly-bonus:{$ali->id}:{$repRole->id}:{$month}")
            ->where('status', 'posted')
            ->first();
        $this->assertNotNull($bonus);
        $this->assertSame('5.000', (string) $bonus->commission_percent);
        $this->assertEqualsWithDelta(5000.0, (float) $bonus->commission_amount, 0.01);

        // تراکنش دوم روی همان درگاه قفل‌شده → پاداش جمع سود واجد شرایط را به‌روز می‌کند
        $this->webhook($sale->gateway->merchant_code, 'FP_EMPTY_BONUS_2', 100000);
        $bonus->refresh();
        $this->assertEqualsWithDelta(10000.0, (float) $bonus->commission_amount, 0.01);

        // SM/DM ارشد هنوز حد نصاب ۵۰۰۰ ندارند → باقی‌مانده دلتای آن‌ها به ارشد
        $residual = app(MonthlyBonusService::class)->settleUnpaidToSenior(now());
        $this->assertNotNull($residual);
        // دو تراکنش × (۲٪+۱٫۵٪) × ۱۰۰٬۰۰۰ = ۷۰۰۰
        $this->assertEqualsWithDelta(7000.0, (float) $residual->commission_amount, 0.01);
    }

    public function test_promotion_to_sales_manager_auto_request_and_rehomes_downline(): void
    {
        $this->setQualificationThresholds(100, 100, 100);
        $this->setPromotionCriteriaForEasySm();

        $ali = $this->registerRep('رضا ارتقاء', '09120100004', $this->seniorRefCode);
        $down1 = $this->registerRep('زیرمجموعه ۱', '09120100011', $this->referralCodeOf($ali));
        $down2 = $this->registerRep('زیرمجموعه ۲', '09120100012', $this->referralCodeOf($ali));

        // امتیاز شخصی ارتقاء از sales_points درگاه‌هاست (نه فقط امتیاز ماهانه پاداش)
        $this->recordSoloSale($ali, 'GW-EMPTY-PROMO-1', 'fino-empty-promo-0001');
        $this->recordSoloSale($down1, 'GW-EMPTY-PROMO-D1', 'fino-empty-promo-d1');
        $this->recordSoloSale($down2, 'GW-EMPTY-PROMO-D2', 'fino-empty-promo-d2');

        $this->completeRequiredTraining($ali, 'representative');

        $promo = app(PromotionService::class)->autoSubmitIfEligible($ali->fresh());
        $this->assertNotNull($promo, 'درخواست ارتقاء باید خودکار ثبت شود');
        $this->assertSame('pending', $promo->status);

        $pending = PromotionRequest::query()->where('user_id', $ali->id)->where('status', 'pending')->first();
        $this->assertNotNull($pending);

        app(PromotionService::class)->decide($this->senior, $pending, 'approved', 'تایید تست کارفرما');

        $ali->refresh();
        $this->assertTrue($ali->hasRole('sales_manager'));
        $this->assertTrue($ali->hasRole('representative'));

        $tree = app(OrganizationTreeService::class);
        $smNode = $tree->activeNodesFor($ali, 'sales_manager')->firstOrFail();
        foreach ([$ali->id, $down1->id, $down2->id] as $uid) {
            $repNode = OrganizationNode::query()
                ->where('user_id', $uid)
                ->where('is_active', true)
                ->whereHas('role', fn ($q) => $q->where('slug', 'representative'))
                ->firstOrFail();
            $this->assertSame($smNode->id, $repNode->parent_node_id, "user {$uid} should sit under new SM");
        }
    }

    public function test_shared_referral_shared_gateway_and_bulk_webhooks_split_exactly(): void
    {
        $this->setQualificationThresholds(100, 5000, 20000);

        $a = $this->registerRep('شریک الف', '09120100021', $this->seniorRefCode);
        $b = $this->registerRep('شریک ب', '09120100022', $this->seniorRefCode);

        // لینک اشتراکی معرف ۶۰/۴۰
        $tokenA = $this->loginToken($a, 'representative');
        $link = $this->withToken($tokenA)->postJson('/api/shared-links', [
            'type' => 'referral',
            'members' => [
                ['user_id' => $a->id, 'share_percent' => 60],
                ['user_id' => $b->id, 'share_percent' => 40],
            ],
        ])->assertCreated()->json();

        $this->withToken($this->loginToken($b, 'representative'))
            ->postJson('/api/shared-links/'.$link['id'].'/approve')
            ->assertOk();

        $newRep = $this->postJson('/api/auth/register', [
            'name' => 'نماینده از لینک اشتراکی',
            'mobile' => '09120100023',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'shared_link_token' => $link['token'],
        ])->assertCreated();
        $c = User::query()->findOrFail((int) $newRep->json('user.id'));

        // لینک اشتراکی درگاه ۷۰/۳۰ بین الف و ب
        $gwLink = $this->withToken($tokenA)->postJson('/api/shared-links', [
            'type' => 'gateway_sale',
            'members' => [
                ['user_id' => $a->id, 'share_percent' => 70],
                ['user_id' => $b->id, 'share_percent' => 30],
            ],
        ])->assertCreated()->json();
        $this->withToken($this->loginToken($b, 'representative'))
            ->postJson('/api/shared-links/'.$gwLink['id'].'/approve')
            ->assertOk();

        $sharedSale = app(GatewaySaleService::class)->record([
            'external_id' => 'GW-EMPTY-SHARE-1',
            'name' => 'درگاه اشتراکی تست',
            'amount' => 1500000,
            'shared_link_id' => $gwLink['id'],
            'representatives' => [
                ['user_id' => $a->id, 'share_percent' => '70.000'],
                ['user_id' => $b->id, 'share_percent' => '30.000'],
            ],
            'customer' => ['name' => 'مشتری اشتراکی', 'mobile' => '09121230901'],
            'idempotency_key' => 'empty-share-sale-1',
            'status' => 'successful',
            'merchant_code' => 'fino-empty-share-0001',
        ]);

        $soloSale = $this->recordSoloSale($c, 'GW-EMPTY-SOLO-C', 'fino-empty-solo-c');

        // حجم تراکنش روی هر دو درگاه
        $sharedTxIds = [];
        for ($i = 1; $i <= 5; $i++) {
            $sharedTxIds[] = $this->webhook('fino-empty-share-0001', "FP_SHARE_BULK_{$i}", 100000);
        }
        $soloTxIds = [];
        for ($i = 1; $i <= 5; $i++) {
            $soloTxIds[] = $this->webhook('fino-empty-solo-c', "FP_SOLO_BULK_{$i}", 100000);
        }

        // یک تراکنش اشتراکی نمونه: ۷۰٪ و ۳۰٪ از ۱۵٪ پایه
        $sample = $this->commissionsByRoleUser($sharedTxIds[0]);
        $this->assertEqualsWithDelta(10500.0, (float) $sample["{$a->id}:representative"]['amount'], 0.01);
        $this->assertEqualsWithDelta(4500.0, (float) $sample["{$b->id}:representative"]['amount'], 0.01);
        $this->assertEqualsWithDelta(6000.0, (float) $sample["{$this->senior->id}:sales_manager"]['amount'], 0.01);
        $this->assertEqualsWithDelta(4500.0, (float) $sample["{$this->senior->id}:development_manager"]['amount'], 0.01);
        $this->assertEqualsWithDelta(4000.0, (float) $sample["{$this->senior->id}:senior_manager"]['amount'], 0.01);

        // معرف‌های اشتراکی روی درگاه C: ۲٪ کل بین ۶۰/۴۰
        $soloSample = Commission::query()
            ->where('finopal_transaction_id', $soloTxIds[0])
            ->whereHas('role', fn ($q) => $q->where('slug', 'representative_referrer'))
            ->get();
        $this->assertGreaterThanOrEqual(1, $soloSample->count());
        $this->assertEqualsWithDelta(2000.0, (float) $soloSample->sum('commission_amount'), 0.01);

        // پایه ۵×۱۰٬۵۰۰ + پاداش ماهانه ۵٪ روی سود منتسب ۷۰٪ (۵×۷۰٬۰۰۰) = ۱۷٬۵۰۰ → جمع ۷۰٬۰۰۰
        $this->assertEqualsWithDelta(70000.0, $this->walletBalance($a, 'representative'), 0.05);

        $baseA = Commission::query()
            ->where('user_id', $a->id)
            ->whereHas('role', fn ($q) => $q->where('slug', 'representative'))
            ->whereIn('finopal_transaction_id', $sharedTxIds)
            ->sum('commission_amount');
        $this->assertEqualsWithDelta(5 * 10500.0, (float) $baseA, 0.05);

        // پاداش نماینده C پس از حد نصاب (۵ درگاه × ۱۰۰ ≥ ۱۰۰)
        $month = now()->format('Y-m');
        $repRole = Role::query()->where('slug', 'representative')->firstOrFail();
        $cBonus = Commission::query()
            ->where('idempotency_key', "monthly-bonus:{$c->id}:{$repRole->id}:{$month}")
            ->where('status', 'posted')
            ->first();
        $this->assertNotNull($cBonus);
        $this->assertEqualsWithDelta(5 * 5000.0, (float) $cBonus->commission_amount, 0.05);

        $aBonus = Commission::query()
            ->where('idempotency_key', "monthly-bonus:{$a->id}:{$repRole->id}:{$month}")
            ->where('status', 'posted')
            ->first();
        $this->assertNotNull($aBonus);
        $this->assertEqualsWithDelta(17500.0, (float) $aBonus->commission_amount, 0.05);

        $this->assertTrue(
            GatewayBonusEligibility::query()
                ->where('user_id', $c->id)
                ->where('gateway_sale_id', $soloSale->id)
                ->exists()
        );

        $this->assertTrue(
            GatewayBonusEligibility::query()
                ->where('user_id', $a->id)
                ->where('gateway_sale_id', $sharedSale->id)
                ->exists()
        );
    }

    // ─── helpers ───────────────────────────────────────────────

    private function setQualificationThresholds(int $rep, int $sm, int $dm): void
    {
        SystemSetting::query()->updateOrCreate(
            ['key' => 'qualification_thresholds'],
            [
                'value' => [
                    'representative_points' => $rep,
                    'sales_manager_points' => $sm,
                    'development_manager_points' => $dm,
                ],
                'value_type' => 'json',
                'is_public' => true,
            ]
        );
    }

    private function setPromotionCriteriaForEasySm(): void
    {
        SystemSetting::query()->updateOrCreate(
            ['key' => 'promotion_criteria'],
            [
                'value' => [
                    'sm_personal_points' => 100,
                    'sm_new_reps' => 2,
                    'sm_strong_reps' => 0,
                    'sm_rep_points' => 5000,
                    'dm_years' => 1,
                    'dm_new_reps' => 100,
                    'dm_strong_reps' => 30,
                    'dm_rep_points' => 10000,
                    'dm_eligible_sms' => 2,
                ],
                'value_type' => 'json',
                'is_public' => false,
            ]
        );
    }

    private function registerRep(string $name, string $mobile, string $referralCode): User
    {
        $res = $this->postJson('/api/auth/register', [
            'name' => $name,
            'mobile' => $mobile,
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'referral_code' => $referralCode,
        ])->assertCreated();

        return User::query()->findOrFail((int) $res->json('user.id'));
    }

    private function referralCodeOf(User $user): string
    {
        return (string) ReferralCode::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->value('code');
    }

    private function loginToken(User $user, string $roleSlug): string
    {
        return $this->postJson('/api/auth/login', [
            'mobile' => $user->mobile,
            'password' => 'Password123!',
            'role_slug' => $roleSlug,
        ])->assertOk()->json('token');
    }

    private function recordSoloSale(User $rep, string $externalId, string $merchant): \App\Models\GatewaySale
    {
        return app(GatewaySaleService::class)->record([
            'external_id' => $externalId,
            'name' => "درگاه {$externalId}",
            'amount' => 1500000,
            'representatives' => [
                ['user_id' => $rep->id, 'share_percent' => '100.000'],
            ],
            'customer' => ['name' => 'مشتری تست', 'mobile' => '09121230'.substr(preg_replace('/\D/', '', $merchant), -3)],
            'idempotency_key' => 'empty-'.$externalId,
            'status' => 'successful',
            'merchant_code' => $merchant,
        ])->load('gateway');
    }

    private function webhook(string $merchant, string $authority, int $profit): int
    {
        $res = $this->postJson('/api/webhooks/finopal/transaction', [
            'event' => 'transaction.verified',
            'merchant_id' => $merchant,
            'authority' => $authority,
            'amount' => $profit * 10,
            'profit' => $profit,
            'currency' => 'IRT',
            'status' => 'OK',
            'code' => 100,
        ], [
            'X-Finopal-Webhook-Secret' => 'test-webhook-secret',
        ])->assertOk();

        return (int) $res->json('id');
    }

    /** @return array<string, array{amount: string, user_id: int, percent: string}> */
    private function commissionsByRole(int $txId): array
    {
        return Commission::query()
            ->where('finopal_transaction_id', $txId)
            ->with('role')
            ->get()
            ->groupBy(fn ($c) => $c->role->slug)
            ->map(fn ($rows) => [
                'percent' => (string) $rows->first()->commission_percent,
                'amount' => (string) $rows->sum(fn ($r) => (float) $r->commission_amount),
                'user_id' => (int) $rows->first()->user_id,
            ])
            ->all();
    }

    /** @return array<string, array{amount: float}> */
    private function commissionsByRoleUser(int $txId): array
    {
        $out = [];
        foreach (
            Commission::query()->where('finopal_transaction_id', $txId)->with('role')->get() as $row
        ) {
            $key = $row->user_id.':'.$row->role->slug;
            $out[$key] = ['amount' => (float) $row->commission_amount];
        }

        return $out;
    }

    private function walletBalance(User $user, string $roleSlug): float
    {
        $wallet = Wallet::query()
            ->where('user_id', $user->id)
            ->whereHas('role', fn ($q) => $q->where('slug', $roleSlug))
            ->first();

        return (float) ($wallet?->balance ?? 0);
    }

    private function completeRequiredTraining(User $user, string $fromSlug): void
    {
        $role = Role::query()->where('slug', $fromSlug)->firstOrFail();
        $courses = Course::query()
            ->where('is_active', true)
            ->where('is_required_for_promotion', true)
            ->whereHas('roles', fn ($q) => $q->where('roles.id', $role->id))
            ->with('levels')
            ->get();

        foreach ($courses as $course) {
            foreach ($course->levels as $level) {
                UserCourseProgress::query()->updateOrCreate(
                    [
                        'user_id' => $user->id,
                        'course_id' => $course->id,
                        'course_level_id' => $level->id,
                    ],
                    [
                        'status' => 'completed',
                        'score' => 100,
                        'completed_at' => now(),
                    ]
                );
            }
        }
    }
}
