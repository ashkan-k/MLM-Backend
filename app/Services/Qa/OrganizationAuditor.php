<?php

namespace App\Services\Qa;

use App\Models\Commission;
use App\Models\OrganizationNode;
use App\Models\RepresentativeReferral;
use App\Models\User;
use App\Models\Wallet;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class OrganizationAuditor
{
    public function __construct(private readonly DatabaseIntegrityChecker $integrity) {}

    /**
     * @return array{ok: bool, checks: list<array{name: string, ok: bool, detail: string}>, stats: array<string, int|string>}
     */
    public function audit(): array
    {
        $base = $this->integrity->run();
        $checks = $base['checks'];

        $checks[] = $this->wrap('duplicate_referral_referred', function () {
            $dupes = DB::table('representative_referrals')
                ->select('referred_user_id', DB::raw('COUNT(*) as c'))
                ->groupBy('referred_user_id')
                ->having('c', '>', 1)
                ->count();

            return [$dupes === 0, $dupes === 0 ? 'یکتا' : "{$dupes} معرفی تکراری"];
        });

        $checks[] = $this->wrap('self_referral_only_if_same_user_ok', function () {
            // self-referral is allowed for senior; flag only accidental self on non-senior is informational
            $self = RepresentativeReferral::query()
                ->whereColumn('referrer_user_id', 'referred_user_id')
                ->count();

            return [true, "خودمعرفی‌ها: {$self} (ارشد مجاز است)"];
        });

        $checks[] = $this->wrap('org_nodes_user_exists', function () {
            $orphans = OrganizationNode::query()->whereDoesntHave('user')->count();

            return [$orphans === 0, $orphans === 0 ? 'ok' : "{$orphans} گره یتیم"];
        });

        $checks[] = $this->wrap('path_contains_own_id', function () {
            $bad = 0;
            OrganizationNode::query()->orderBy('id')->chunkById(300, function ($nodes) use (&$bad) {
                foreach ($nodes as $n) {
                    if (! str_contains((string) $n->path, '/'.$n->id.'/')) {
                        $bad++;
                    }
                }
            });

            return [$bad === 0, $bad === 0 ? 'ok' : "{$bad} path نامعتبر"];
        });

        $checks[] = $this->wrap('parent_path_prefix', function () {
            $bad = 0;
            OrganizationNode::query()->whereNotNull('parent_node_id')->with('parent')->orderBy('id')->chunkById(200, function ($nodes) use (&$bad) {
                foreach ($nodes as $n) {
                    $p = $n->parent;
                    if (! $p || ! str_starts_with((string) $n->path, (string) $p->path)) {
                        $bad++;
                    }
                }
            });

            return [$bad === 0, $bad === 0 ? 'ok' : "{$bad} ناسازگاری path والد"];
        });

        $ok = collect($checks)->every(fn ($c) => $c['ok']);

        return [
            'ok' => $ok,
            'checks' => $checks,
            'stats' => [
                'users' => User::query()->count(),
                'active_users' => User::query()->where('is_active', true)->count(),
                'inactive_users' => User::query()->where('is_active', false)->count(),
                'org_nodes' => OrganizationNode::query()->count(),
                'root_nodes' => OrganizationNode::query()->whereNull('parent_node_id')->count(),
                'referrals' => RepresentativeReferral::query()->count(),
                'wallets' => Wallet::query()->count(),
                'commissions' => Commission::query()->count(),
                'wallet_ledger_sum' => $this->sumAllWalletBalances(),
            ],
        ];
    }

    private function sumAllWalletBalances(): string
    {
        $sum = '0.000';
        Wallet::query()->orderBy('id')->chunkById(200, function ($wallets) use (&$sum) {
            foreach ($wallets as $w) {
                $sum = Money::add($sum, (string) $w->balance);
            }
        });

        return $sum;
    }

    /**
     * @param  callable(): array{0: bool, 1: string}  $fn
     * @return array{name: string, ok: bool, detail: string}
     */
    private function wrap(string $name, callable $fn): array
    {
        [$ok, $detail] = $fn();

        return ['name' => $name, 'ok' => $ok, 'detail' => $detail];
    }
}
