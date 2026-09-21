<?php

namespace App\Services\Commission;

use App\Models\FinopalTransaction;
use App\Models\GatewaySale;
use App\Models\RepresentativeReferral;
use App\Models\Role;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class CommissionEngine
{
    public function __construct(
        private readonly CommissionRuleResolver $rules,
        private readonly CommissionCalculator $calculator,
        private readonly CommissionDistributor $distributor,
        private readonly CommissionLedger $ledger,
        private readonly MonthlyBonusService $monthlyBonus,
    ) {}

    public function process(GatewaySale $sale, ?FinopalTransaction $transaction = null): array
    {
        if ($sale->status !== 'successful') {
            return [];
        }
        if ($transaction === null) {
            return [];
        }

        return DB::transaction(function () use ($sale, $transaction) {
            $sale->load(['representatives.user', 'referrers.user', 'managers.user', 'managers.role']);
            $this->distributor->split($sale);
            $created = [];
            $at = $transaction->paid_at ?? $sale->sold_at;
            $base = (string) $transaction->profit;
            $touched = [];
            $referrerRate = $this->basePercent('representative_referrer', $at);

            // نمایندگان: پورسانت کامل سهم خود (بدون کسر معرف)
            foreach ($sale->representatives as $row) {
                $share = (string) $row->share_percent;
                $repRate = $this->calculator->sharedPercent(
                    $this->basePercent('representative', $at),
                    $share
                );
                $created[] = $this->creditRole(
                    $sale,
                    $transaction,
                    $row->user,
                    'representative',
                    $repRate,
                    $base,
                    [
                        'share_percent' => $share,
                        'sales_points' => $row->sales_points,
                        'finopal_transaction_id' => $transaction->id,
                    ]
                );
                $touched[] = [$row->user, 'representative'];
            }

            // معرف: ۲٪ از کل سود تراکنش (نه از برش مالکیت نماینده معرفی‌شده)
            // هر معرف یکتا حداکثر یک‌بار از همین تراکنش سهم می‌گیرد.
            $referrerPayouts = $this->referrerPayoutsFromTotal($sale, $base, $referrerRate);
            foreach ($referrerPayouts as $userId => $payout) {
                if (Money::cmp($payout['amount'], '0') <= 0) {
                    continue;
                }
                $user = User::query()->find($userId);
                if (! $user) {
                    continue;
                }
                $created[] = $this->creditRole(
                    $sale,
                    $transaction,
                    $user,
                    'representative_referrer',
                    $referrerRate,
                    $base,
                    [
                        'share_percent' => Money::normalize($payout['member_weight'], 3),
                        'transaction_profit' => Money::normalize($base, 3),
                        'finopal_transaction_id' => $transaction->id,
                        'calculated_from_total_profit' => true,
                    ],
                    $payout['amount']
                );
                $touched[] = [$user, 'representative_referrer'];
            }

            foreach ($sale->managers as $row) {
                $created[] = $this->creditRole(
                    $sale,
                    $transaction,
                    $row->user,
                    $row->role->slug,
                    $this->basePercent($row->role->slug, $at),
                    $base,
                    ['manager_role' => $row->role->slug, 'finopal_transaction_id' => $transaction->id]
                );
                $touched[] = [$row->user, $row->role->slug];
            }

            foreach ($touched as [$user, $slug]) {
                $this->monthlyBonus->refresh($user, $slug, $at instanceof \Carbon\CarbonInterface ? $at : now());
            }

            return $created;
        });
    }

    /**
     * ۲٪ از کل سود تراکنش برای هر معرف یکتا (معرف‌های مشترک لینک، همان ۲٪ را بین خود تقسیم می‌کنند).
     *
     * @return array<int, array{amount: string, member_weight: string}>
     */
    private function referrerPayoutsFromTotal(GatewaySale $sale, string $base, string $referrerRate): array
    {
        if (Money::cmp($referrerRate, '0') <= 0) {
            return [];
        }

        $pool = $this->calculator->amount($base, $referrerRate);
        /** @var array<int, true> $seenPrimary */
        $seenPrimary = [];
        /** @var array<int, array{amount: string, member_weight: string}> $payouts */
        $payouts = [];

        foreach ($sale->representatives as $row) {
            $referral = RepresentativeReferral::query()
                ->with('shareMembers')
                ->where('referred_user_id', $row->user_id)
                ->first();
            if (! $referral) {
                continue;
            }

            $primaryId = (int) $referral->referrer_user_id;
            if (isset($seenPrimary[$primaryId])) {
                continue;
            }
            $seenPrimary[$primaryId] = true;

            $members = $referral->shareMembers->isNotEmpty()
                ? $referral->shareMembers
                : collect([(object) [
                    'user_id' => $referral->referrer_user_id,
                    'share_percent' => '100.000',
                ]]);

            foreach ($members as $member) {
                $memberId = (int) $member->user_id;
                $memberShare = (string) $member->share_percent;
                $part = Money::percentOf($pool, $memberShare);
                if (! isset($payouts[$memberId])) {
                    $payouts[$memberId] = [
                        'amount' => '0.000',
                        'member_weight' => '0.000',
                    ];
                }
                $payouts[$memberId]['amount'] = Money::add($payouts[$memberId]['amount'], $part);
                $payouts[$memberId]['member_weight'] = Money::add(
                    $payouts[$memberId]['member_weight'],
                    $memberShare
                );
            }
        }

        return $payouts;
    }

    private function creditRole(
        GatewaySale $sale,
        FinopalTransaction $transaction,
        User $user,
        string $roleSlug,
        string $percent,
        string $base,
        array $meta = [],
        ?string $amountOverride = null,
    ) {
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        $at = $transaction->paid_at ?? $sale->sold_at;
        $version = $this->rules->resolve($roleSlug, $at);
        $amount = $amountOverride !== null
            ? Money::normalize($amountOverride, 3)
            : $this->calculator->amount($base, $percent);

        $key = sprintf(
            'tx:%s:sale:%s:user:%s:role:%s',
            $transaction->id,
            $sale->id,
            $user->id,
            $role->id
        );

        return $this->ledger->post(
            $user,
            $role,
            $sale,
            $version?->id,
            $base,
            $percent,
            $amount,
            $key,
            $meta,
            $transaction->id
        );
    }

    private function basePercent(string $roleSlug, mixed $at): string
    {
        $version = $this->rules->resolve($roleSlug, $at);

        return $version ? Money::normalize((string) $version->percent) : '0.000';
    }
}
