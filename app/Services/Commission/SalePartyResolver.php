<?php

namespace App\Services\Commission;

use App\Models\OrganizationNode;
use App\Models\RepresentativeReferral;
use App\Models\Role;
use App\Models\User;
use App\Support\Money;

/**
 * Resolve ownership parties (reps / referrers / managers) for any commissionable sale.
 */
class SalePartyResolver
{
    /**
     * @param  list<array{user_id:int, share_percent:string|int|float}>  $reps
     * @return list<array{user_id:int, share_percent:string, commission_percent:string}>
     */
    public function resolveReferrers(array $reps): array
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
                $key = (int) $member->user_id;
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

    /**
     * @return list<array{user_id:int, role_id:int, commission_percent:int|string}>
     */
    public function resolveManagers(int $representativeUserId): array
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

    public function findUserByIdentity(?string $nationalId = null, ?string $mobile = null, ?int $userId = null): ?User
    {
        if ($userId) {
            return User::query()->find($userId);
        }

        if ($nationalId) {
            $normalized = preg_replace('/\D+/', '', $nationalId) ?: $nationalId;
            $user = User::query()->where('national_id', $normalized)->first()
                ?? User::query()->where('national_id', $nationalId)->first();
            if ($user) {
                return $user;
            }
        }

        if ($mobile) {
            $digits = preg_replace('/\D+/', '', $mobile) ?? '';
            if (str_starts_with($digits, '98') && strlen($digits) === 12) {
                $digits = '0'.substr($digits, 2);
            }

            return User::query()->where('mobile', $digits)->first();
        }

        return null;
    }
}
