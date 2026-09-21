<?php

namespace App\Console\Commands;

use App\Models\Course;
use App\Models\Gateway;
use App\Models\GatewayRepresentative;
use App\Models\GatewaySale;
use App\Models\ReferralCode;
use App\Models\RepresentativeReferral;
use App\Models\Role;
use App\Models\User;
use App\Models\UserCourseProgress;
use App\Models\UserRole;
use App\Services\Integration\Finopal\FinopalTransactionService;
use App\Services\Organization\OrganizationTreeService;
use App\Services\Promotion\PromotionService;
use App\Services\Wallet\WalletService;
use App\Support\ReferralCodeGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Lab data for empty-org commission + pending SM promotion tests.
 */
class SeedEmptyOrgScenarioLabCommand extends Command
{
    use SkipsBrokenConsoleConfirm;

    protected $signature = 'finopal:seed-empty-org-lab
        {--force : بدون پرسش تأیید}';

    protected $description = 'تراکنش روی درگاه‌های شایان + نماینده واجد شرایط ارتقاء با درخواست pending';

    public function handle(
        FinopalTransactionService $txs,
        OrganizationTreeService $tree,
        WalletService $wallets,
        PromotionService $promotions,
    ): int {
        if (! $this->confirmedOrForced('تراکنش‌های شایان و نماینده تست ارتقاء ساخته شوند؟', true)) {
            return self::SUCCESS;
        }

        $this->regeneratePredictableCodes();

        $shayan = User::query()->where('mobile', '09125555555')->first();
        if (! $shayan) {
            $this->error('کاربر شایان کریمی (09125555555) پیدا نشد.');

            return self::FAILURE;
        }

        $senior = User::query()
            ->whereHas('roles', fn ($q) => $q->where('slug', 'senior_manager'))
            ->orderBy('id')
            ->first();
        if (! $senior) {
            $this->error('مدیر ارشد پیدا نشد.');

            return self::FAILURE;
        }

        $saleMerchants = GatewaySale::query()
            ->where('status', 'successful')
            ->whereHas('representatives', fn ($q) => $q->where('user_id', $shayan->id))
            ->with('gateway')
            ->get()
            ->map(fn (GatewaySale $s) => $s->gateway?->merchant_code)
            ->filter()
            ->unique()
            ->values();

        if ($saleMerchants->isEmpty()) {
            $this->warn('درگاه successful برای شایان نیست؛ تراکنشی ثبت نشد.');
        }

        $ingested = [];
        foreach ($saleMerchants as $i => $merchant) {
            $tx = $txs->ingest([
                'event' => 'transaction.verified',
                'merchant_id' => $merchant,
                'authority' => 'LAB_SHAYAN_'.($i + 1).'_'.now()->format('His'),
                'amount' => 2500000,
                'profit' => 200000,
                'currency' => 'IRT',
                'status' => 'OK',
                'code' => 100,
            ]);
            $ingested[] = [
                'merchant' => $merchant,
                'tx_id' => $tx->id,
                'commissions' => $tx->commissions->count(),
            ];
            $this->info("تراکنش روی {$merchant}: {$tx->commissions->count()} پورسانت");
        }

        $candidate = $this->seedPromotionCandidate($senior, $tree, $wallets, $promotions);

        $this->newLine();
        $this->table(
            ['کلید', 'موبایل', 'رمز', 'کد معرف'],
            [
                ['senior', $senior->mobile, 'Password123!', ReferralCode::query()->where('user_id', $senior->id)->value('code')],
                ['shayan', $shayan->mobile, '(همان رمز فعلی)', ReferralCode::query()->where('user_id', $shayan->id)->value('code')],
                ['promo_candidate', $candidate['mobile'], $candidate['password'], $candidate['referral_code']],
            ]
        );
        $this->line('درخواست ارتقاء pending id='.$candidate['promotion_id'].' — از پنل ارشد / ارتقاءها بررسی و تایید دستی کنید.');
        $this->line('پورسانت‌ها: کیف‌پول نقش‌های مدیر ارشد (SM/DM/senior) + نماینده شایان را ببینید.');

        return self::SUCCESS;
    }

    private function regeneratePredictableCodes(): void
    {
        ReferralCode::query()->each(function (ReferralCode $row) {
            if (ReferralCodeGenerator::looksPredictable($row->code)) {
                $row->code = ReferralCodeGenerator::unique();
                $row->save();
                $this->comment("کد معرف user#{$row->user_id} به {$row->code} عوض شد.");
            }
        });
    }

    /**
     * @return array{mobile: string, password: string, referral_code: string, promotion_id: int}
     */
    private function seedPromotionCandidate(
        User $senior,
        OrganizationTreeService $tree,
        WalletService $wallets,
        PromotionService $promotions,
    ): array {
        $roles = Role::query()->get()->keyBy('slug');
        $password = 'Password123!';
        $mobile = '09129990001';

        return DB::transaction(function () use ($senior, $tree, $wallets, $promotions, $roles, $password, $mobile) {
            $candidate = User::query()->updateOrCreate(
                ['mobile' => $mobile],
                [
                    'name' => 'نامزد ارتقاء مدیر فروش',
                    'password' => Hash::make($password),
                    'is_active' => true,
                ]
            );

            foreach (['representative', 'representative_referrer'] as $i => $slug) {
                UserRole::query()->updateOrCreate(
                    ['user_id' => $candidate->id, 'role_id' => $roles[$slug]->id],
                    [
                        'effective_from' => now()->subMonths(6)->toDateString(),
                        'is_primary' => $i === 0,
                        'is_active' => true,
                        'effective_to' => null,
                    ]
                );
                $wallets->walletFor($candidate, $roles[$slug]);
            }

            $code = ReferralCode::query()->firstOrCreate(
                ['user_id' => $candidate->id],
                ['code' => ReferralCodeGenerator::unique(), 'source' => 'finopal', 'is_active' => true]
            );
            if (ReferralCodeGenerator::looksPredictable($code->code)) {
                $code->code = ReferralCodeGenerator::unique();
                $code->save();
            }

            RepresentativeReferral::query()->updateOrCreate(
                ['referred_user_id' => $candidate->id],
                [
                    'referrer_user_id' => $senior->id,
                    'source' => 'finopal',
                    'referral_code_id' => ReferralCode::query()->where('user_id', $senior->id)->value('id'),
                ]
            );

            $parent = $tree->registrationParentFor($senior);
            if (! $tree->activeNodesFor($candidate, 'representative')->first()) {
                $tree->attach($candidate, $roles['representative'], $parent, now()->subMonths(6)->toDateString());
            }

            // Personal points >= 10000
            $this->ensurePointsSale($candidate, 'LAB-PROMO-SELF', 12000);

            $criteria = \App\Models\SystemSetting::getValue('promotion_criteria', [
                'sm_new_reps' => 60,
                'sm_strong_reps' => 24,
                'sm_rep_points' => 5000,
            ]);
            $needReps = (int) ($criteria['sm_new_reps'] ?? 60);
            $needStrong = (int) ($criteria['sm_strong_reps'] ?? 24);
            $strongPts = (float) ($criteria['sm_rep_points'] ?? 5000);

            for ($i = 1; $i <= $needReps; $i++) {
                $repMobile = sprintf('0912888%04d', $i);
                $rep = User::query()->updateOrCreate(
                    ['mobile' => $repMobile],
                    [
                        'name' => "زیرمجموعه نامزد #{$i}",
                        'password' => Hash::make($password),
                        'is_active' => true,
                    ]
                );
                UserRole::query()->updateOrCreate(
                    ['user_id' => $rep->id, 'role_id' => $roles['representative']->id],
                    [
                        'effective_from' => now()->subMonths(3)->toDateString(),
                        'is_primary' => true,
                        'is_active' => true,
                        'effective_to' => null,
                    ]
                );
                $wallets->walletFor($rep, $roles['representative']);
                ReferralCode::query()->firstOrCreate(
                    ['user_id' => $rep->id],
                    ['code' => ReferralCodeGenerator::unique(), 'source' => 'finopal', 'is_active' => true]
                );
                RepresentativeReferral::query()->updateOrCreate(
                    ['referred_user_id' => $rep->id],
                    [
                        'referrer_user_id' => $candidate->id,
                        'source' => 'finopal',
                        'referral_code_id' => $code->id,
                    ]
                );
                if (! $tree->activeNodesFor($rep, 'representative')->first()) {
                    $tree->attach($rep, $roles['representative'], $parent, now()->subMonths(2)->toDateString());
                }
                if ($i <= $needStrong) {
                    $this->ensurePointsSale($rep, "LAB-PROMO-STRONG-{$i}", $strongPts);
                }
            }

            // Required training
            $courses = Course::query()
                ->where('is_active', true)
                ->where('is_required_for_promotion', true)
                ->with('levels')
                ->whereHas('roles', fn ($q) => $q->where('roles.id', $roles['representative']->id))
                ->get();
            foreach ($courses as $course) {
                foreach ($course->levels as $level) {
                    UserCourseProgress::query()->updateOrCreate(
                        [
                            'user_id' => $candidate->id,
                            'course_id' => $course->id,
                            'course_level_id' => $level->id,
                        ],
                        [
                            'status' => 'completed',
                            'score' => 100,
                            'completed_at' => now()->subDays(3),
                        ]
                    );
                }
            }

            // Drop old pending for this user then create fresh
            \App\Models\PromotionRequest::query()
                ->where('user_id', $candidate->id)
                ->where('status', 'pending')
                ->delete();

            $request = $promotions->request($candidate->fresh('roles'), 'representative', 'sales_manager');

            return [
                'mobile' => $mobile,
                'password' => $password,
                'referral_code' => $code->fresh()->code,
                'promotion_id' => $request->id,
            ];
        });
    }

    private function ensurePointsSale(User $user, string $externalId, float $points): void
    {
        $gateway = Gateway::query()->updateOrCreate(
            ['external_id' => $externalId],
            [
                'merchant_code' => strtolower($externalId),
                'name' => 'درگاه آزمایشی امتیاز '.$user->name,
                'source' => 'lab',
                'sale_amount' => 0,
                'is_active' => true,
            ]
        );

        $sale = GatewaySale::query()->updateOrCreate(
            ['idempotency_key' => $externalId],
            [
                'gateway_id' => $gateway->id,
                'external_id' => $externalId,
                'amount' => 0,
                'full_sales_points' => $points,
                'status' => 'successful',
                'source' => 'lab',
                'sold_at' => now()->subDays(10),
            ]
        );

        GatewayRepresentative::query()->updateOrCreate(
            [
                'gateway_sale_id' => $sale->id,
                'user_id' => $user->id,
            ],
            [
                'share_percent' => 100,
                'sales_points' => $points,
            ]
        );
    }
}
