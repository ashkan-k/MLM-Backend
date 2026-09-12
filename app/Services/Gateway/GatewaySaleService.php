<?php

namespace App\Services\Gateway;

use App\Models\Customer;
use App\Models\Gateway;
use App\Models\GatewaySale;
use App\Models\OrganizationNode;
use App\Models\RepresentativeReferral;
use App\Models\Role;
use App\Models\SharedLink;
use App\Models\User;
use App\Services\Commission\CommissionDistributor;
use App\Services\Commission\CommissionEngine;
use App\Services\Referral\SharedLinkService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GatewaySaleService
{
    public function __construct(
        private readonly CommissionEngine $engine,
        private readonly SharedLinkService $sharedLinks,
        private readonly CommissionDistributor $distributor,
    ) {}

    public function record(array $payload): GatewaySale
    {
        return DB::transaction(function () use ($payload) {
            $key = $payload['idempotency_key'] ?? null;
            if ($key && $existing = GatewaySale::query()->where('idempotency_key', $key)->first()) {
                return $existing;
            }

            $customer = null;
            if (! empty($payload['customer'])) {
                $customer = Customer::query()->create([
                    'name' => $payload['customer']['name'],
                    'mobile' => $payload['customer']['mobile'] ?? null,
                    'metadata' => $payload['customer']['metadata'] ?? null,
                ]);
            }

            $gateway = Gateway::query()->firstOrCreate(
                ['external_id' => $payload['external_id']],
                [
                    'name' => $payload['name'] ?? $payload['external_id'],
                    'source' => $payload['source'] ?? 'finopal',
                    'sale_amount' => $payload['amount'],
                    'is_active' => true,
                ]
            );

            $sale = GatewaySale::query()->create([
                'gateway_id' => $gateway->id,
                'customer_id' => $customer?->id,
                'shared_link_id' => $payload['shared_link_id'] ?? null,
                'amount' => $payload['amount'],
                'full_sales_points' => config('finopal.full_sale_points'),
                'status' => 'successful',
                'sold_at' => $payload['sold_at'] ?? now(),
                'idempotency_key' => $key ?: 'sale-'.Str::uuid(),
            ]);

            $reps = $this->resolveRepresentatives($payload);
            $this->distributor->assertShares($reps);

            foreach ($reps as $rep) {
                $sale->representatives()->create([
                    'user_id' => $rep['user_id'],
                    'share_percent' => $rep['share_percent'],
                    'sales_points' => Money::percentOf((string) $sale->full_sales_points, (string) $rep['share_percent']),
                ]);
            }

            foreach ($this->resolveReferrers($reps) as $ref) {
                $sale->referrers()->create($ref);
            }

            foreach ($this->resolveManagers($reps[0]['user_id']) as $manager) {
                $sale->managers()->create($manager);
            }

            if (! empty($payload['shared_link_id'])) {
                $this->sharedLinks->consume(SharedLink::query()->findOrFail($payload['shared_link_id']));
            }

            $this->engine->process($sale->fresh(['representatives', 'referrers', 'managers.role']));

            return $sale->fresh(['gateway', 'representatives.user', 'referrers.user', 'managers.user', 'commissions']);
        });
    }

    private function resolveRepresentatives(array $payload): array
    {
        if (! empty($payload['shared_link_id'])) {
            $link = SharedLink::query()->with('members')->findOrFail($payload['shared_link_id']);
            if ($link->status !== 'active') {
                abort(422, 'لینک اشتراکی فعال نیست.');
            }

            return $link->members->map(fn ($m) => [
                'user_id' => $m->user_id,
                'share_percent' => (string) $m->share_percent,
            ])->all();
        }

        if (! empty($payload['representatives'])) {
            return $payload['representatives'];
        }

        return [[
            'user_id' => $payload['representative_user_id'],
            'share_percent' => '100.000',
        ]];
    }

    private function resolveReferrers(array $reps): array
    {
        $shares = [];
        foreach ($reps as $rep) {
            $referral = RepresentativeReferral::query()
                ->with('shareMembers')
                ->where('referred_user_id', $rep['user_id'])
                ->first();
            if (! $referral) {
                continue;
            }
            $members = $referral->shareMembers->isNotEmpty()
                ? $referral->shareMembers
                : collect([(object) ['user_id' => $referral->referrer_user_id, 'share_percent' => '100.000']]);

            foreach ($members as $member) {
                $key = $member->user_id;
                $part = Money::percentOf((string) $rep['share_percent'], (string) $member->share_percent);
                $shares[$key] = Money::add($shares[$key] ?? '0.000', $part);
            }
        }

        return collect($shares)->map(fn ($percent, $userId) => [
            'user_id' => (int) $userId,
            'share_percent' => $percent,
            'commission_percent' => $percent,
        ])->values()->all();
    }

    private function resolveManagers(int $representativeUserId): array
    {
        $node = OrganizationNode::query()
            ->where('user_id', $representativeUserId)
            ->where('is_active', true)
            ->whereHas('role', fn ($q) => $q->where('slug', 'representative'))
            ->first();

        $out = [];
        $seen = [];
        while ($node?->parent) {
            $node = $node->parent()->with('role')->first();
            if (! $node || isset($seen[$node->role_id])) {
                continue;
            }
            if (in_array($node->role->slug, ['sales_manager', 'development_manager', 'senior_manager'], true)) {
                $out[] = [
                    'user_id' => $node->user_id,
                    'role_id' => $node->role_id,
                    'commission_percent' => 0,
                ];
                $seen[$node->role_id] = true;
            }
        }

        foreach (['sales_manager', 'development_manager', 'senior_manager'] as $slug) {
            $already = collect($out)->contains(fn ($row) => Role::query()->find($row['role_id'])?->slug === $slug);
            if (! $already) {
                $senior = User::query()->whereHas('roles', fn ($q) => $q->where('slug', 'senior_manager'))->first();
                $role = Role::query()->where('slug', $slug)->first();
                if ($senior && $role) {
                    $out[] = [
                        'user_id' => $senior->id,
                        'role_id' => $role->id,
                        'commission_percent' => 0,
                    ];
                }
            }
        }

        return $out;
    }
}
