<?php

namespace App\Services\Commission;

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

    public function process(GatewaySale $sale): array
    {
        return DB::transaction(function () use ($sale) {
            $sale->load(['representatives.user', 'referrers.user', 'managers.user', 'managers.role']);
            $this->distributor->split($sale);
            $created = [];

            foreach ($sale->representatives as $row) {
                $created[] = $this->creditRole(
                    $sale,
                    $row->user,
                    'representative',
                    $this->calculator->sharedPercent(
                        $this->resolvedPercent('representative', $row->user, $sale),
                        (string) $row->share_percent
                    ),
                    ['share_percent' => $row->share_percent, 'sales_points' => $row->sales_points]
                );
            }

            foreach ($sale->referrers as $row) {
                $created[] = $this->creditRole(
                    $sale,
                    $row->user,
                    'representative_referrer',
                    $this->calculator->sharedPercent(
                        $this->resolvedPercent('representative_referrer', $row->user, $sale),
                        (string) $row->share_percent
                    ),
                    ['share_percent' => $row->share_percent]
                );
            }

            foreach ($sale->managers as $row) {
                $created[] = $this->creditRole(
                    $sale,
                    $row->user,
                    $row->role->slug,
                    $this->resolvedPercent($row->role->slug, $row->user, $sale),
                    ['manager_role' => $row->role->slug]
                );
            }

            return array_values(array_filter($created));
        });
    }

    private function resolvedPercent(string $roleSlug, User $user, GatewaySale $sale): string
    {
        $version = $this->rules->resolve($roleSlug, $sale->sold_at);
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        $qualified = $this->qualification->isQualified($roleSlug, $user, $role->id, $sale->sold_at);

        if (! $version) {
            return '0.000';
        }

        return $this->calculator->qualifiedPercent(
            (string) $version->percent,
            $version->qualified_percent !== null ? (string) $version->qualified_percent : null,
            $qualified
        );
    }

    private function creditRole(GatewaySale $sale, User $user, string $roleSlug, string $percent, array $metadata): mixed
    {
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        $version = $this->rules->resolve($roleSlug, $sale->sold_at);
        $amount = $this->calculator->amount((string) $sale->amount, $percent);
        $key = implode(':', ['commission', $sale->id, $user->id, $role->id]);

        return $this->ledger->post(
            $user,
            $role,
            $sale,
            $version?->id,
            (string) $sale->amount,
            $percent,
            $amount,
            $key,
            $metadata + ['qualified_percent' => $percent]
        );
    }
}
