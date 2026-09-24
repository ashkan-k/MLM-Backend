<?php

namespace App\Services\Qa;

use App\Models\Gateway;
use App\Models\GatewaySale;
use App\Models\OrganizationNode;
use App\Models\ReferralCode;
use App\Models\RepresentativeReferral;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Gateway\GatewaySaleService;
use App\Services\Integration\Finopal\FinopalTransactionService;
use App\Services\Organization\OrganizationTreeService;
use App\Support\Money;
use App\Support\ReferralCodeGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Builds a deterministic, multi-root MLM world for QA.
 * Uses only real roles: senior_manager, development_manager, sales_manager, representative, representative_referrer.
 */
class MegaOrgWorldBuilder
{
    private int $seed;

    private string $passwordHash;

    /** @var array<string, Role> */
    private $roles;

    private OrganizationTreeService $tree;

    /** @var list<array{key: string, user_id: int, mobile: string, note: string}> */
    private array $edgeUsers = [];

    /** @var array<string, mixed> */
    private array $meta = [];

    public function __construct()
    {
        $this->tree = app(OrganizationTreeService::class);
    }

    /**
     * @return array{meta: array<string, mixed>, edge_users: list<array{key: string, user_id: int, mobile: string, note: string}>}
     */
    public function build(?int $totalUsers = null, ?int $totalRoots = null, ?int $seed = null): array
    {
        $this->seed = $seed ?? (int) config('qa.random_seed', 12345);
        mt_srand($this->seed);

        $totalUsers = max(50, min($totalUsers ?? (int) config('qa.total_users', 10000), 100000));
        $totalRoots = max(2, min($totalRoots ?? (int) config('qa.total_roots', 20), 200));
        $maxDepth = max(5, min((int) config('qa.max_tree_depth', 20), 40));
        $maxWide = max(50, min((int) config('qa.max_children_per_node', 500), 2000));
        $orderTarget = max(0, min((int) config('qa.order_count', 2000), 50000));
        $txTarget = max(0, min((int) config('qa.transaction_count', 5000), 100000));

        $this->roles = Role::query()->get()->keyBy('slug')->all();
        foreach (['senior_manager', 'development_manager', 'sales_manager', 'representative', 'representative_referrer'] as $slug) {
            if (! isset($this->roles[$slug])) {
                throw new \RuntimeException("نقش {$slug} موجود نیست. ابتدا migrate --seed پایه را اجرا کنید.");
            }
        }

        $this->passwordHash = Hash::make((string) config('qa.password', 'Password123!'));
        $mobileSeq = 9100000000;

        $roots = [];
        $salesNodes = [];
        for ($r = 0; $r < $totalRoots; $r++) {
            $senior = $this->createUser($mobileSeq++, "ROOT-".str_pad((string) ($r + 1), 3, '0', STR_PAD_LEFT), [
                'senior_manager', 'development_manager', 'sales_manager', 'representative',
            ], now()->subYears(2)->subDays($r));
            $chain = $this->tree->ensureSeniorManagerChain($senior);
            $roots[] = $senior;
            $salesNodes[] = $chain['sales'];
            if ($r === 0) {
                $this->edge('USER_ROOT_PRIMARY', $senior, 'ریشه اصلی برای سناریوهای E2E');
            }
        }

        $created = count($roots);
        $remaining = $totalUsers - $created;

        // Budget: deep / wide / balanced / unbalanced / random
        $deepBudget = (int) min($maxDepth * $totalRoots, (int) ($remaining * 0.08));
        $wideBudget = (int) min($maxWide * min(5, $totalRoots), (int) ($remaining * 0.30));
        $balancedBudget = (int) ($remaining * 0.25);
        $unbalancedBudget = (int) ($remaining * 0.22);
        $randomBudget = max(0, $remaining - $deepBudget - $wideBudget - $balancedBudget - $unbalancedBudget);

        // 3.2 Deep chains (one per first N roots)
        $deepPerRoot = max(5, intdiv($deepBudget, max(1, min($totalRoots, 10))));
        for ($r = 0; $r < min($totalRoots, 10) && $created < $totalUsers; $r++) {
            $parent = $salesNodes[$r];
            $prev = $roots[$r];
            $depth = min($maxDepth, $deepPerRoot);
            for ($d = 0; $d < $depth && $created < $totalUsers; $d++) {
                $u = $this->createUser($mobileSeq++, "Deep-R{$r}-D{$d}", ['representative'], now()->subDays(400 - $d));
                $node = $this->tree->attach($u, $this->roles['representative'], $parent, now()->subDays(400 - $d)->toDateString());
                $this->linkReferral($prev, $u);
                $parent = $node;
                $prev = $u;
                $created++;
                if ($r === 0 && $d === $depth - 1) {
                    $this->edge('USER_DEEP_TREE', $u, "عمیق‌ترین گره عمق {$depth}");
                }
            }
        }

        // 3.3 Wide under root 0 sales
        $wideCount = min($wideBudget, $maxWide, $totalUsers - $created);
        $wideParent = $salesNodes[0];
        $wideReferrer = $this->createUser($mobileSeq++, 'WIDE-REFERRER', ['representative', 'representative_referrer'], now()->subYear());
        $this->tree->attach($wideReferrer, $this->roles['representative'], $wideParent, now()->subYear()->toDateString());
        $created++;
        $this->edge('USER_1000_REFERRALS', $wideReferrer, 'والد معرفی گسترده');
        for ($i = 0; $i < $wideCount && $created < $totalUsers; $i++) {
            $u = $this->createUser($mobileSeq++, "Wide-{$i}", ['representative'], now()->subDays(200 - ($i % 180)));
            $this->tree->attach($u, $this->roles['representative'], $wideParent, now()->subDays(100)->toDateString());
            if ($i < min(1000, $wideCount)) {
                $this->linkReferral($wideReferrer, $u);
            }
            if ($i === 0) {
                $this->edge('USER_NO_REFERRALS', $u, 'بدون رکورد معرفی (عمداً لینک نشد به غیر از درخت)');
            }
            $created++;
            $this->applyPopulationState($u, $i);
        }

        // 3.4 Balanced: mid SM nodes under several roots
        $midsPerRoot = 3;
        $perMid = max(1, intdiv($balancedBudget, max(1, min($totalRoots, 8) * $midsPerRoot)));
        for ($r = 0; $r < min($totalRoots, 8) && $created < $totalUsers; $r++) {
            for ($m = 0; $m < $midsPerRoot && $created < $totalUsers; $m++) {
                $sm = $this->createUser($mobileSeq++, "Bal-SM-R{$r}-M{$m}", ['sales_manager', 'representative'], now()->subMonths(8));
                $smNode = $this->tree->attach($sm, $this->roles['sales_manager'], $salesNodes[$r], now()->subMonths(8)->toDateString());
                $created++;
                for ($c = 0; $c < $perMid && $created < $totalUsers; $c++) {
                    $u = $this->createUser($mobileSeq++, "Bal-R{$r}-M{$m}-C{$c}", ['representative'], now()->subMonths(3));
                    $this->tree->attach($u, $this->roles['representative'], $smNode, now()->subMonths(3)->toDateString());
                    $this->linkReferral($sm, $u);
                    $created++;
                    $this->applyPopulationState($u, $c + $m * 17);
                }
            }
        }

        // 3.5 Unbalanced: huge on root1, tiny on root2
        if ($totalRoots >= 2) {
            $tiny = $this->createUser($mobileSeq++, 'UNBAL-TINY', ['representative'], now()->subDays(10));
            $this->tree->attach($tiny, $this->roles['representative'], $salesNodes[1], now()->toDateString());
            $created++;
            $this->edge('USER_SMALL_BRANCH', $tiny, 'شاخه خیلی کوچک');

            $hugeN = min($unbalancedBudget, $totalUsers - $created);
            for ($i = 0; $i < $hugeN; $i++) {
                $u = $this->createUser($mobileSeq++, "Unbal-{$i}", ['representative'], now()->subDays(90 - ($i % 80)));
                $this->tree->attach($u, $this->roles['representative'], $salesNodes[1], now()->subDays(60)->toDateString());
                if ($i % 9 === 0) {
                    $this->linkReferral($roots[1], $u);
                }
                $created++;
                $this->applyPopulationState($u, $i * 3);
            }
            $this->edge('USER_LARGE_TREE', $roots[1], "ریشه شاخه بزرگ (~{$hugeN} فرزند مستقیم زیر SM)");
        }

        // Fill remaining as random valid attaches under existing sales nodes
        $parentPool = array_map(fn ($n) => $n->id, $salesNodes);
        while ($created < $totalUsers) {
            $u = $this->createUser($mobileSeq++, "Rand-{$created}", ['representative'], now()->subDays(mt_rand(1, 700)));
            $pid = $parentPool[mt_rand(0, count($parentPool) - 1)];
            $pNode = OrganizationNode::query()->find($pid) ?? $salesNodes[0];
            $node = $this->tree->attach($u, $this->roles['representative'], $pNode, now()->toDateString());
            if (mt_rand(0, 100) < 40) {
                $ref = $roots[mt_rand(0, count($roots) - 1)];
                $this->linkReferral($ref, $u);
            }
            $parentPool[] = $node->id;
            if (count($parentPool) > 800) {
                array_shift($parentPool);
            }
            $created++;
            $this->applyPopulationState($u, $created);
        }

        // Dedicated edge wallets / finance samples
        $this->seedEdgeFinanceAndHistory($roots, $salesNodes, $orderTarget, $txTarget, $mobileSeq);

        $this->meta = [
            'random_seed' => $this->seed,
            'requested_users' => $totalUsers,
            'actual_users' => User::query()->count(),
            'roots' => $totalRoots,
            'max_depth_config' => $maxDepth,
            'organization_nodes' => OrganizationNode::query()->count(),
            'referrals' => RepresentativeReferral::query()->count(),
            'wallets' => Wallet::query()->count(),
            'wallet_transactions' => WalletTransaction::query()->count(),
            'gateway_sales' => GatewaySale::query()->count(),
            'gateways' => Gateway::query()->count(),
            'edge_users' => count($this->edgeUsers),
            'generated_at' => now()->toIso8601String(),
        ];

        return ['meta' => $this->meta, 'edge_users' => $this->edgeUsers];
    }

    /** @return list<array{key: string, user_id: int, mobile: string, note: string}> */
    public function edgeUsers(): array
    {
        return $this->edgeUsers;
    }

    /** @param  list<string>  $roleSlugs */
    private function createUser(int $mobileInt, string $name, array $roleSlugs, \DateTimeInterface $registeredAt): User
    {
        $mobile = '09'.str_pad(substr((string) abs($mobileInt), -9), 9, '0', STR_PAD_LEFT);
        $user = User::query()->create([
            'name' => $name,
            'mobile' => $mobile,
            'email' => 'mega'.$mobileInt.'@finopal.qa',
            'password' => $this->passwordHash,
            'is_active' => true,
            'created_at' => $registeredAt,
            'updated_at' => $registeredAt,
        ]);

        foreach ($roleSlugs as $i => $slug) {
            UserRole::query()->create([
                'user_id' => $user->id,
                'role_id' => $this->roles[$slug]->id,
                'effective_from' => $registeredAt->format('Y-m-d'),
                'is_primary' => $i === 0,
                'is_active' => true,
            ]);
            if ($this->roles[$slug]->is_organizational) {
                Wallet::query()->create([
                    'user_id' => $user->id,
                    'role_id' => $this->roles[$slug]->id,
                    'currency' => 'IRT',
                    'balance' => '0.000',
                    'held_balance' => '0.000',
                    'is_active' => true,
                ]);
            }
        }

        ReferralCode::query()->create([
            'user_id' => $user->id,
            'code' => ReferralCodeGenerator::unique(),
            'source' => 'finopal',
            'is_active' => true,
        ]);

        return $user;
    }

    private function linkReferral(User $referrer, User $referred): void
    {
        if (RepresentativeReferral::query()->where('referred_user_id', $referred->id)->exists()) {
            return;
        }
        if (! $referrer->roles()->where('slug', 'representative_referrer')->exists()) {
            UserRole::query()->firstOrCreate(
                ['user_id' => $referrer->id, 'role_id' => $this->roles['representative_referrer']->id],
                [
                    'effective_from' => now()->toDateString(),
                    'is_primary' => false,
                    'is_active' => true,
                ]
            );
            Wallet::query()->firstOrCreate(
                [
                    'user_id' => $referrer->id,
                    'role_id' => $this->roles['representative_referrer']->id,
                    'currency' => 'IRT',
                ],
                ['balance' => '0.000', 'held_balance' => '0.000', 'is_active' => true]
            );
        }
        RepresentativeReferral::query()->create([
            'referred_user_id' => $referred->id,
            'referrer_user_id' => $referrer->id,
            'source' => 'mega_org',
            'referral_code_id' => ReferralCode::query()->where('user_id', $referrer->id)->value('id'),
        ]);
    }

    private function applyPopulationState(User $user, int $salt): void
    {
        $bucket = $salt % 10;
        // 20% inactive (is_active=false) — only real status in app
        if ($bucket < 2) {
            $user->forceFill(['is_active' => false])->save();
        }
    }

    private function edge(string $key, User $user, string $note): void
    {
        $this->edgeUsers[] = [
            'key' => $key,
            'user_id' => $user->id,
            'mobile' => $user->mobile,
            'note' => $note,
        ];
    }

    /**
     * @param  list<User>  $roots
     * @param  list<OrganizationNode>  $salesNodes
     */
    private function seedEdgeFinanceAndHistory(array $roots, array $salesNodes, int $orderTarget, int $txTarget, int &$mobileSeq): void
    {
        $sales = app(GatewaySaleService::class);
        $ingest = app(FinopalTransactionService::class);
        $repRole = $this->roles['representative'];

        // Edge: zero balance (default), large balance
        $zero = $this->createUser($mobileSeq++, 'EDGE-ZERO-BAL', ['representative'], now()->subDays(5));
        $this->tree->attach($zero, $repRole, $salesNodes[0], now()->toDateString());
        $this->edge('USER_ZERO_BALANCE', $zero, 'موجودی صفر');

        $rich = $this->createUser($mobileSeq++, 'EDGE-LARGE-BAL', ['representative'], now()->subDays(30));
        $this->tree->attach($rich, $repRole, $salesNodes[0], now()->toDateString());
        $w = Wallet::query()->where('user_id', $rich->id)->where('role_id', $repRole->id)->first();
        if ($w) {
            DB::table('wallets')->where('id', $w->id)->update(['balance' => '5000000.000']);
            DB::table('wallet_transactions')->insert([
                'wallet_id' => $w->id,
                'type' => 'seed_credit',
                'amount' => '5000000.000',
                'balance_before' => '0.000',
                'balance_after' => '5000000.000',
                'idempotency_key' => 'mega-edge-rich-'.$w->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $this->edge('USER_LARGE_BALANCE', $rich, 'موجودی بزرگ ۵ میلیون');
        $this->edge('USER_ROLE_TRANSFER_TARGET', $rich, 'هدف انتقال نقش/مالکیت');
        $this->edge('USER_OWNERSHIP_TRANSFER_TARGET', $zero, 'هدف انتقال مالکیت');

        $noTx = $this->createUser($mobileSeq++, 'EDGE-NO-TX', ['representative'], now()->subDays(2));
        $this->tree->attach($noTx, $repRole, $salesNodes[0], now()->toDateString());
        $this->edge('USER_NO_TRANSACTIONS', $noTx, 'بدون تراکنش مالی');
        $this->edge('USER_RECENTLY_REGISTERED', $noTx, 'ثبت‌نام تازه');

        $inactive = $this->createUser($mobileSeq++, 'EDGE-INACTIVE', ['representative'], now()->subYear());
        $this->tree->attach($inactive, $repRole, $salesNodes[0], now()->subYear()->toDateString());
        $inactive->forceFill(['is_active' => false])->save();
        $this->edge('USER_INACTIVE', $inactive, 'غیرفعال (is_active=false)');

        // Historical sales + webhooks for a sample of active reps under root 0
        $sampleReps = OrganizationNode::query()
            ->where('path', 'like', $salesNodes[0]->path.'%')
            ->whereHas('role', fn ($q) => $q->where('slug', 'representative'))
            ->whereHas('user', fn ($q) => $q->where('is_active', true))
            ->limit(min(200, max(20, intdiv($orderTarget, 5))))
            ->with('user')
            ->get();

        $ordersMade = 0;
        $txsMade = 0;
        $i = 0;
        foreach ($sampleReps as $node) {
            if ($ordersMade >= $orderTarget) {
                break;
            }
            $user = $node->user;
            if (! $user) {
                continue;
            }
            $bucket = $i % 10;
            $howMany = match (true) {
                $bucket === 0 => 0,
                $bucket < 4 => mt_rand(1, 5),
                $bucket < 7 => mt_rand(6, 15),
                default => mt_rand(16, 40),
            };
            for ($n = 0; $n < $howMany && $ordersMade < $orderTarget; $n++) {
                $merchant = sprintf('mega-m-%d-%d', $user->id, $n);
                $sale = $sales->record([
                    'external_id' => 'MEGA-GW-'.$user->id.'-'.$n,
                    'name' => 'Mega Sale '.$user->id.'-'.$n,
                    'amount' => 100000 + ($n * 1000),
                    'representative_user_id' => $user->id,
                    'idempotency_key' => 'mega-sale-'.$user->id.'-'.$n,
                    'status' => 'successful',
                    'merchant_code' => $merchant,
                ]);
                $ordersMade++;

                // Mix verified / failed / skip
                $outcome = $n % 7;
                if ($outcome === 1) {
                    // failed — no commission
                    continue;
                }
                if ($txsMade >= $txTarget) {
                    continue;
                }
                $profit = (string) (50000 + $n * 500);
                try {
                    $ingest->ingest([
                        'event' => 'transaction.verified',
                        'merchant_id' => $merchant,
                        'authority' => 'MEGA_AUTH_'.$sale->id.'_'.$n,
                        'amount' => $profit,
                        'profit' => $profit,
                        'currency' => 'IRT',
                        'status' => 'verified',
                        'code' => 100,
                        'idempotency_key' => 'mega-tx-'.$sale->id.'-'.$n,
                    ]);
                    $txsMade++;
                } catch (\Throwable) {
                    // ignore individual ingest failures in bulk world build
                }
            }
            if ($howMany >= 16 && ! collect($this->edgeUsers)->contains(fn ($e) => $e['key'] === 'USER_MANY_TRANSACTIONS')) {
                $this->edge('USER_MANY_TRANSACTIONS', $user, 'نماینده پرحجم');
                $this->edge('USER_HIGH_COMMISSION', $user, 'پورسانت بالا (نمونه‌ای)');
            }
            $i++;
        }

        $this->meta['orders_seeded'] = $ordersMade;
        $this->meta['transactions_seeded'] = $txsMade;
    }
}
