<?php

namespace App\Services\Qa;

use App\Models\Commission;
use App\Models\OrganizationNode;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Independent consistency checks — does not use CommissionEngine / WalletService math for expected balances.
 */
class DatabaseIntegrityChecker
{
    /**
     * @return array{ok: bool, checks: list<array{name: string, ok: bool, detail: string}>}
     */
    public function run(): array
    {
        $checks = [];

        $checks[] = $this->check('wallet_balance_matches_ledger', function () {
            $mismatches = 0;
            $sample = [];
            Wallet::query()->orderBy('id')->chunkById(200, function ($wallets) use (&$mismatches, &$sample) {
                foreach ($wallets as $wallet) {
                    $expected = $this->ledgerNetBalance((int) $wallet->id);
                    if (Money::cmp($expected, (string) $wallet->balance) !== 0) {
                        $mismatches++;
                        if (count($sample) < 5) {
                            $sample[] = "wallet#{$wallet->id} expected={$expected} actual={$wallet->balance}";
                        }
                    }
                }
            });

            return [$mismatches === 0, $mismatches === 0 ? 'all wallets match ledger' : "{$mismatches} mismatches: ".implode('; ', $sample)];
        });

        $checks[] = $this->check('no_duplicate_wallet_tx_idempotency', function () {
            $dupes = DB::table('wallet_transactions')
                ->select('idempotency_key', DB::raw('COUNT(*) as c'))
                ->groupBy('idempotency_key')
                ->having('c', '>', 1)
                ->count();

            return [$dupes === 0, $dupes === 0 ? 'unique' : "{$dupes} duplicate idempotency keys"];
        });

        $checks[] = $this->check('no_duplicate_commission_idempotency', function () {
            $dupes = DB::table('commissions')
                ->select('idempotency_key', DB::raw('COUNT(*) as c'))
                ->groupBy('idempotency_key')
                ->having('c', '>', 1)
                ->count();

            return [$dupes === 0, $dupes === 0 ? 'unique' : "{$dupes} duplicate commission keys"];
        });

        $checks[] = $this->check('no_org_tree_cycles', function () {
            $nodes = OrganizationNode::query()->get(['id', 'parent_node_id', 'path']);
            $byId = $nodes->keyBy('id');
            $cycles = 0;
            foreach ($nodes as $node) {
                $seen = [];
                $cur = $node;
                $guard = 0;
                while ($cur && $cur->parent_node_id) {
                    if (isset($seen[$cur->id]) || $guard++ > 5000) {
                        $cycles++;
                        break;
                    }
                    $seen[$cur->id] = true;
                    $cur = $byId->get($cur->parent_node_id);
                }
                if ($node->path && preg_match('#/(\d+)/#', (string) $node->path)) {
                    $parts = array_filter(explode('/', trim((string) $node->path, '/')));
                    if (count($parts) !== count(array_unique($parts))) {
                        $cycles++;
                    }
                }
            }

            return [$cycles === 0, $cycles === 0 ? 'acyclic' : "{$cycles} cycle indicators"];
        });

        $checks[] = $this->check('no_self_parent', function () {
            $bad = OrganizationNode::query()->whereColumn('id', 'parent_node_id')->count();

            return [$bad === 0, $bad === 0 ? 'ok' : "{$bad} self-parents"];
        });

        $checks[] = $this->check('orphan_wallet_transactions', function () {
            $orphans = WalletTransaction::query()
                ->whereDoesntHave('wallet')
                ->count();

            return [$orphans === 0, $orphans === 0 ? 'ok' : "{$orphans} orphan txs"];
        });

        $checks[] = $this->check('commissions_have_user_and_role', function () {
            $bad = Commission::query()
                ->where(function ($q) {
                    $q->whereNull('user_id')->orWhereNull('role_id');
                })
                ->count();

            return [$bad === 0, $bad === 0 ? 'ok' : "{$bad} incomplete commissions"];
        });

        $checks[] = $this->check('held_balance_non_negative', function () {
            $bad = Wallet::query()->where('held_balance', '<', 0)->count();

            return [$bad === 0, $bad === 0 ? 'ok' : "{$bad} negative held"];
        });

        $ok = collect($checks)->every(fn ($c) => $c['ok']);

        return ['ok' => $ok, 'checks' => $checks];
    }

    /**
     * Independent ledger net: sum(balance_after − balance_before) in id order.
     * Does not call WalletService / CommissionEngine.
     */
    public function ledgerNetBalance(int $walletId): string
    {
        $sum = '0.000';
        WalletTransaction::query()
            ->where('wallet_id', $walletId)
            ->orderBy('id')
            ->chunk(500, function ($rows) use (&$sum) {
                foreach ($rows as $tx) {
                    $delta = Money::sub(
                        Money::normalize((string) $tx->balance_after),
                        Money::normalize((string) $tx->balance_before)
                    );
                    $sum = Money::add($sum, $delta);
                }
            });

        return $sum;
    }

    /**
     * @param  callable(): array{0: bool, 1: string}  $fn
     * @return array{name: string, ok: bool, detail: string}
     */
    private function check(string $name, callable $fn): array
    {
        [$ok, $detail] = $fn();

        return ['name' => $name, 'ok' => $ok, 'detail' => $detail];
    }
}
