<?php

namespace App\Services\Commission;

use App\Models\FinopalTransaction;
use App\Models\GatewaySale;
use App\Models\Role;
use App\Models\User;
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

            foreach ($sale->representatives as $row) {
                $created[] = $this->creditRole(
                    $sale,
                    $transaction,
                    $row->user,
                    'representative',
                    $this->calculator->sharedPercent(
                        $this->basePercent('representative', $at),
                        (string) $row->share_percent
                    ),
                    $base,
                    ['share_percent' => $row->share_percent, 'sales_points' => $row->sales_points, 'finopal_transaction_id' => $transaction->id]
                );
                $touched[] = [$row->user, 'representative'];
            }

            foreach ($sale->referrers as $row) {
                $created[] = $this->creditRole(
                    $sale,
                    $transaction,
                    $row->user,
                    'representative_referrer',
                    $this->calculator->sharedPercent(
                        $this->basePercent('representative_referrer', $at),
                        (string) $row->share_percent
                    ),
                    $base,
                    ['share_percent' => $row->share_percent, 'finopal_transaction_id' => $transaction->id]
                );
                $touched[] = [$row->user, 'representative_referrer'];
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
    ): mixed {
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        $version = $this->rules->resolve($roleSlug, $transaction->paid_at ?? $sale->sold_at);
        $amount = $this->calculator->amount($base, $percent);
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
