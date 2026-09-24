<?php

namespace Database\Seeders;

use App\Models\OrganizationNode;
use App\Models\ReferralCode;
use App\Models\RepresentativeReferral;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Wallet;
use App\Services\Organization\OrganizationTreeService;
use App\Support\ReferralCodeGenerator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Large realistic org for load/perf QA (structures A–F).
 * NOT for production. Prefer isolated DB (name contains test/load).
 */
class LoadOrgSeeder extends Seeder
{
    public function run(): void
    {
        $count = (int) (config('qa.load_user_count') ?? env('QA_LOAD_USER_COUNT', 10000));
        $count = max(100, min($count, 50000));

        $roles = Role::query()->get()->keyBy('slug');
        foreach (['senior_manager', 'development_manager', 'sales_manager', 'representative', 'representative_referrer'] as $slug) {
            if (! $roles->has($slug)) {
                throw new \RuntimeException("Missing role {$slug}. Run migrate --seed first.");
            }
        }

        $password = Hash::make('Password123!');
        $tree = app(OrganizationTreeService::class);

        $this->command?->info("LoadOrgSeeder: building {$count} users (structures A–F)…");

        // Structure E: two independent roots (seniors)
        $seniorA = $this->makeUser(9000000001, 'Load Senior A', $password);
        $seniorB = $this->makeUser(9000000002, 'Load Senior B', $password);
        $this->grantRoles($seniorA, $roles, ['senior_manager', 'development_manager', 'sales_manager', 'representative']);
        $this->grantRoles($seniorB, $roles, ['senior_manager', 'development_manager', 'sales_manager', 'representative']);

        $chainA = $tree->ensureSeniorManagerChain($seniorA);
        $chainB = $tree->ensureSeniorManagerChain($seniorB);
        $salesA = $chainA['sales'];
        $salesB = $chainB['sales'];

        $remaining = $count - 2;
        $buckets = $this->allocateBuckets($remaining);

        $created = 0;
        $mobileBase = 9000000100;

        // A — Deep tree under salesA
        $parent = $salesA;
        $prevUser = $seniorA;
        for ($i = 0; $i < $buckets['deep']; $i++) {
            $u = $this->makeUser($mobileBase++, "Deep {$i}", $password);
            $this->grantRoles($u, $roles, ['representative']);
            $node = $tree->attach($u, $roles['representative'], $parent, now()->subDays(30)->toDateString());
            $this->linkReferral($prevUser, $u);
            $parent = $node;
            $prevUser = $u;
            $created++;
            if ($i === 0 || $i === (int) ($buckets['deep'] / 2)) {
                $this->maybeWalletCredit($u, $roles['representative']);
            }
        }

        // B — Wide: many direct under salesA
        for ($i = 0; $i < $buckets['wide']; $i++) {
            $u = $this->makeUser($mobileBase++, "Wide {$i}", $password);
            $this->grantRoles($u, $roles, ['representative']);
            $tree->attach($u, $roles['representative'], $salesA, now()->subDays(20)->toDateString());
            if ($i % 7 === 0) {
                $this->linkReferral($seniorA, $u);
            }
            $created++;
        }

        // C — Balanced branches under salesA (3 SM-like mid nodes)
        $mids = [];
        for ($b = 0; $b < 3; $b++) {
            $mid = $this->makeUser($mobileBase++, "BalMid {$b}", $password);
            $this->grantRoles($mid, $roles, ['sales_manager', 'representative']);
            $mids[] = $tree->attach($mid, $roles['sales_manager'], $salesA, now()->subDays(15)->toDateString());
            $created++;
        }
        $perBranch = intdiv($buckets['balanced'], max(1, count($mids)));
        foreach ($mids as $mi => $midNode) {
            for ($i = 0; $i < $perBranch; $i++) {
                $u = $this->makeUser($mobileBase++, "Bal {$mi}-{$i}", $password);
                $this->grantRoles($u, $roles, ['representative']);
                $tree->attach($u, $roles['representative'], $midNode, now()->subDays(10)->toDateString());
                $created++;
            }
        }

        // D — Unbalanced: huge branch on B vs tiny
        $tiny = $this->makeUser($mobileBase++, 'Unbal Tiny', $password);
        $this->grantRoles($tiny, $roles, ['representative']);
        $tree->attach($tiny, $roles['representative'], $salesB, now()->toDateString());
        $created++;
        for ($i = 0; $i < $buckets['unbalanced']; $i++) {
            $u = $this->makeUser($mobileBase++, "Unbal Big {$i}", $password);
            $this->grantRoles($u, $roles, ['representative']);
            $tree->attach($u, $roles['representative'], $salesB, now()->subDays(5)->toDateString());
            if ($i % 11 === 0) {
                User::query()->whereKey($u->id)->update(['is_active' => false]);
            }
            $created++;
        }

        // F — Random under A/B sales
        $parents = OrganizationNode::query()
            ->whereIn('id', [$salesA->id, $salesB->id])
            ->orWhere('path', 'like', $salesA->path.'%')
            ->limit(200)
            ->pluck('id')
            ->all();
        if ($parents === []) {
            $parents = [$salesA->id];
        }

        while ($created < $count) {
            $u = $this->makeUser($mobileBase++, "Rand {$created}", $password);
            $this->grantRoles($u, $roles, ['representative']);
            $pid = $parents[array_rand($parents)];
            $pNode = OrganizationNode::query()->find($pid) ?? $salesA;
            $node = $tree->attach($u, $roles['representative'], $pNode, now()->toDateString());
            $parents[] = $node->id;
            if (count($parents) > 500) {
                array_shift($parents);
            }
            $created++;
        }

        $this->command?->info("LoadOrgSeeder done. users≈{$created}+roots, nodes=".OrganizationNode::query()->count());
    }

    /** @return array{deep: int, wide: int, balanced: int, unbalanced: int} */
    private function allocateBuckets(int $remaining): array
    {
        $deep = (int) max(50, min(400, (int) ($remaining * 0.05)));
        $wide = (int) max(200, (int) ($remaining * 0.35));
        $balanced = (int) max(200, (int) ($remaining * 0.25));
        $unbalanced = max(50, $remaining - $deep - $wide - $balanced - 20);

        return compact('deep', 'wide', 'balanced', 'unbalanced');
    }

    private function makeUser(int $mobileInt, string $name, string $password): User
    {
        // Always 11-digit IR mobile 09xxxxxxxxx
        $mobile = '09'.str_pad(substr((string) abs($mobileInt), -9), 9, '0', STR_PAD_LEFT);

        return User::query()->updateOrCreate(
            ['mobile' => $mobile],
            [
                'name' => $name,
                'email' => 'load'.$mobileInt.'@finopal.load',
                'password' => $password,
                'is_active' => true,
            ]
        );
    }

    /** @param  array<string, Role>  $roles */
    private function grantRoles(User $user, $roles, array $slugs): void
    {
        foreach ($slugs as $i => $slug) {
            UserRole::query()->updateOrCreate(
                ['user_id' => $user->id, 'role_id' => $roles[$slug]->id],
                [
                    'effective_from' => now()->subYear()->toDateString(),
                    'is_primary' => $i === 0,
                    'is_active' => true,
                    'effective_to' => null,
                ]
            );
            Wallet::query()->firstOrCreate(
                [
                    'user_id' => $user->id,
                    'role_id' => $roles[$slug]->id,
                    'currency' => 'IRT',
                ],
                ['balance' => '0.000', 'held_balance' => '0.000', 'is_active' => true]
            );
        }

        ReferralCode::query()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'code' => ReferralCodeGenerator::unique(),
                'source' => 'finopal',
                'is_active' => true,
            ]
        );
    }

    private function linkReferral(User $referrer, User $referred): void
    {
        RepresentativeReferral::query()->firstOrCreate(
            ['referred_user_id' => $referred->id],
            [
                'referrer_user_id' => $referrer->id,
                'source' => 'load_seeder',
            ]
        );
    }

    private function maybeWalletCredit(User $user, Role $role): void
    {
        // Direct ledger-style credit via SQL for speed (not production path)
        $wallet = Wallet::query()->where('user_id', $user->id)->where('role_id', $role->id)->first();
        if (! $wallet) {
            return;
        }
        DB::table('wallets')->where('id', $wallet->id)->update(['balance' => '1000.000']);
        DB::table('wallet_transactions')->insert([
            'wallet_id' => $wallet->id,
            'type' => 'seed_credit',
            'amount' => '1000.000',
            'balance_before' => '0.000',
            'balance_after' => '1000.000',
            'idempotency_key' => 'load-seed-'.$wallet->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
