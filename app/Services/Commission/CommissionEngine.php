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
            /** @var array<int, array{amount: string, attributed_profit: string, ownership_weight: string}> $referrerPayouts */
            $referrerPayouts = [];

            foreach ($sale->representatives as $row) {
                $share = (string) $row->share_percent;
                $repRate = $this->calculator->sharedPercent(
                    $this->basePercent('representative', $at),
                    $share
                );
                $gross = $this->calculator->amount($base, $repRate);
                $ownershipSlice = Money::percentOf($base, $share);
                $deduction = '0.000';

                $referral = RepresentativeReferral::query()
                    ->with('shareMembers')
                    ->where('referred_user_id', $row->user_id)
                    ->first();

                if ($referral && Money::cmp($referrerRate, '0') > 0) {
                    // سهم معرف از برش سود همین نماینده برداشته می‌شود (نه پرداخت جدا روی کل سود).
                    $deduction = $this->calculator->amount($ownershipSlice, $referrerRate);
                    $members = $referral->shareMembers->isNotEmpty()
                        ? $referral->shareMembers
                        : collect([(object) [
                            'user_id' => $referral->referrer_user_id,
                            'share_percent' => '100.000',
                        ]]);

                    foreach ($members as $member) {
                        $memberId = (int) $member->user_id;
                        $memberShare = (string) $member->share_percent;
                        $part = Money::percentOf($deduction, $memberShare);
                        $memberSlice = Money::percentOf($ownershipSlice, $memberShare);
                        if (! isset($referrerPayouts[$memberId])) {
                            $referrerPayouts[$memberId] = [
                                'amount' => '0.000',
                                'attributed_profit' => '0.000',
                                'ownership_weight' => '0.000',
                            ];
                        }
                        $referrerPayouts[$memberId]['amount'] = Money::add($referrerPayouts[$memberId]['amount'], $part);
                        $referrerPayouts[$memberId]['attributed_profit'] = Money::add(
                            $referrerPayouts[$memberId]['attributed_profit'],
                            $memberSlice
                        );
                        $referrerPayouts[$memberId]['ownership_weight'] = Money::add(
                            $referrerPayouts[$memberId]['ownership_weight'],
                            Money::percentOf($share, $memberShare)
                        );
                    }
                }

                if (Money::cmp($deduction, $gross) > 0) {
                    $deduction = $gross;
                }
                $net = Money::sub($gross, $deduction);

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
                        'gross_commission_amount' => Money::normalize($gross, 3),
                        'referrer_deduction_amount' => Money::normalize($deduction, 3),
                        'ownership_profit_slice' => Money::normalize($ownershipSlice, 3),
                    ],
                    $net
                );
                $touched[] = [$row->user, 'representative'];
            }

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
                    $payout['attributed_profit'],
                    [
                        'share_percent' => '100.000',
                        'referred_ownership_percent' => Money::normalize($payout['ownership_weight'], 3),
                        'transaction_profit' => Money::normalize($base, 3),
                        'finopal_transaction_id' => $transaction->id,
                        'sourced_from_representative_share' => true,
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

            return array_values(array_filter($created));
        });
    }

    private function basePercent(string $roleSlug, mixed $at): string
    {
        $version = $this->rules->resolve($roleSlug, $at);

        return $version ? (string) $version->percent : '0.000';
    }

    private function creditRole(
        GatewaySale $sale,
        FinopalTransaction $transaction,
        User $user,
        string $roleSlug,
        string $percent,
        string $base,
        array $metadata,
        ?string $amountOverride = null,
    ): mixed {
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        $version = $this->rules->resolve($roleSlug, $transaction->paid_at ?? $sale->sold_at);
        $amount = $amountOverride !== null
            ? Money::normalize($amountOverride, 3)
            : $this->calculator->amount($base, $percent);
        $key = implode(':', ['commission', 'tx', $transaction->id, $user->id, $role->id]);

        return $this->ledger->post(
            $user,
            $role,
            $sale,
            $version?->id,
            $base,
            $percent,
            $amount,
            $key,
            $metadata + ['rate_type' => 'base'],
            $transaction->id,
        );
    }
}
