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
        private readonly QualificationService $qualification,
        private readonly CommissionCalculator $calculator,
        private readonly CommissionDistributor $distributor,
        private readonly CommissionLedger $ledger,
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

            foreach ($sale->representatives as $row) {
                $created[] = $this->creditRole(
                    $sale,
                    $transaction,
                    $row->user,
                    'representative',
                    $this->calculator->sharedPercent(
                        $this->resolvedPercent('representative', $row->user, $at),
                        (string) $row->share_percent
                    ),
                    $base,
                    ['share_percent' => $row->share_percent, 'sales_points' => $row->sales_points, 'finopal_transaction_id' => $transaction->id]
                );
            }

            foreach ($sale->referrers as $row) {
                $created[] = $this->creditRole(
                    $sale,
                    $transaction,
                    $row->user,
                    'representative_referrer',
                    $this->calculator->sharedPercent(
                        $this->resolvedPercent('representative_referrer', $row->user, $at),
                        (string) $row->share_percent
                    ),
                    $base,
                    ['share_percent' => $row->share_percent, 'finopal_transaction_id' => $transaction->id]
                );
            }

            foreach ($sale->managers as $row) {
                $created[] = $this->creditRole(
                    $sale,
                    $transaction,
                    $row->user,
                    $row->role->slug,
                    $this->resolvedPercent($row->role->slug, $row->user, $at),
                    $base,
                    ['manager_role' => $row->role->slug, 'finopal_transaction_id' => $transaction->id]
                );
            }

            return array_values(array_filter($created));
        });
    }

    private function resolvedPercent(string $roleSlug, User $user, mixed $at): string
    {
        $version = $this->rules->resolve($roleSlug, $at);
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        $qualified = $this->qualification->isQualified($roleSlug, $user, $role->id, $at);

        if (! $version) {
            return '0.000';
        }

        return $this->calculator->qualifiedPercent(
            (string) $version->percent,
            $version->qualified_percent !== null ? (string) $version->qualified_percent : null,
            $qualified
        );
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
            $metadata + ['qualified_percent' => $percent],
            $transaction->id,
        );
    }
}
