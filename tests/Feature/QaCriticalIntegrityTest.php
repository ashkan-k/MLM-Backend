<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\FinopalTransaction;
use App\Models\GatewaySale;
use App\Models\OrganizationNode;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Gateway\GatewaySaleService;
use App\Services\Organization\OrganizationTreeService;
use App\Services\Qa\DatabaseIntegrityChecker;
use App\Services\Wallet\WalletService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * QA Phases 9–14, 23: independent financial + tree + webhook integrity.
 */
class QaCriticalIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_independent_commission_math_matches_posted_amounts(): void
    {
        $rep = User::query()->where('mobile', '09125555555')->firstOrFail();
        $sale = app(GatewaySaleService::class)->record([
            'external_id' => 'GW-QA-MATH-1',
            'name' => 'QA Math',
            'amount' => 1_000_000,
            'representative_user_id' => $rep->id,
            'idempotency_key' => 'qa-math-sale-1',
            'status' => 'successful',
            'merchant_code' => 'fino-qa-math-0001',
        ]);

        $profit = '1000000.000';
        $this->postJson('/api/webhooks/finopal/transaction', [
            'event' => 'transaction.verified',
            'merchant_id' => 'fino-qa-math-0001',
            'authority' => 'FP_QA_MATH_1',
            'amount' => 1000000,
            'profit' => 1000000,
            'currency' => 'IRT',
            'status' => 'verified',
            'code' => 100,
        ], ['X-Finopal-Webhook-Secret' => 'test-webhook-secret'])->assertOk();

        // Independent expected (Money only — not CommissionEngine)
        $expectedRep = Money::percentOf($profit, '15');
        $expectedRef = Money::percentOf($profit, '2');
        $expectedSm = Money::percentOf($profit, '6');
        $expectedDm = Money::percentOf($profit, '4.5');
        $expectedSenior = Money::percentOf($profit, '4');

        $rows = Commission::query()
            ->where('gateway_sale_id', $sale->id)
            ->where('idempotency_key', 'like', 'tx:%')
            ->with('role')
            ->get();

        $sumRole = function (string $slug) use ($rows): string {
            $total = '0.000';
            foreach ($rows->where(fn ($c) => $c->role->slug === $slug) as $c) {
                $total = Money::add($total, (string) $c->commission_amount);
            }

            return $total;
        };

        $this->assertSame($expectedRep, $sumRole('representative'));
        $this->assertSame($expectedRef, $sumRole('representative_referrer'));
        $this->assertSame($expectedSm, $sumRole('sales_manager'));
        $this->assertSame($expectedDm, $sumRole('development_manager'));
        $this->assertSame($expectedSenior, $sumRole('senior_manager'));
    }

    public function test_fivefold_webhook_replay_creates_one_settlement(): void
    {
        $rep = User::query()->where('mobile', '09125555555')->firstOrFail();
        $sale = app(GatewaySaleService::class)->record([
            'external_id' => 'GW-QA-REPLAY-1',
            'name' => 'QA Replay',
            'amount' => 250000,
            'representative_user_id' => $rep->id,
            'idempotency_key' => 'qa-replay-sale-1',
            'status' => 'successful',
            'merchant_code' => 'fino-qa-replay-0001',
        ]);

        $payload = [
            'event' => 'transaction.verified',
            'merchant_id' => 'fino-qa-replay-0001',
            'authority' => 'FP_QA_REPLAY_1',
            'amount' => 250000,
            'profit' => 250000,
            'currency' => 'IRT',
            'status' => 'verified',
            'code' => 100,
        ];

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/webhooks/finopal/transaction', $payload, [
                'X-Finopal-Webhook-Secret' => 'test-webhook-secret',
            ])->assertOk();
        }

        $this->assertSame(1, FinopalTransaction::query()->where('authority', 'FP_QA_REPLAY_1')->count());
        $commissionRows = Commission::query()->where('gateway_sale_id', $sale->id)->count();
        $this->assertSame(
            Commission::query()->where('gateway_sale_id', $sale->id)->distinct('idempotency_key')->count('idempotency_key'),
            $commissionRows
        );
        $this->assertSame(
            1,
            WalletTransaction::query()
                ->where('idempotency_key', 'like', 'wallet-tx:%:sale:'.$sale->id.':%')
                ->where('type', 'commission_credit')
                ->whereHas('wallet', fn ($q) => $q->where('user_id', $rep->id))
                ->count()
        );
    }

    public function test_wallet_ledger_invariant_after_credits_and_debits(): void
    {
        $user = User::query()->where('mobile', '09125555555')->firstOrFail();
        $role = Role::query()->where('slug', 'representative')->firstOrFail();
        $wallets = app(WalletService::class);
        $wallet = $wallets->walletFor($user, $role);

        $before = Money::normalize((string) $wallet->balance);
        $wallets->credit($wallet, '100.500', 'qa_credit', 'qa-w-1');
        $wallets->credit($wallet, '50.250', 'qa_credit', 'qa-w-2');
        $wallets->debit($wallet->fresh(), '30.000', 'qa_debit', 'qa-w-3');
        // duplicate credit must not double
        $wallets->credit($wallet->fresh(), '100.500', 'qa_credit', 'qa-w-1');

        $expected = Money::sub(Money::add(Money::add($before, '100.500'), '50.250'), '30.000');
        $actual = Money::normalize((string) $wallet->fresh()->balance);
        $this->assertSame($expected, $actual);

        $checker = app(DatabaseIntegrityChecker::class);
        $this->assertSame($expected, $checker->ledgerNetBalance((int) $wallet->id));
    }

    public function test_reparent_rejects_cycle_into_descendant(): void
    {
        $tree = app(OrganizationTreeService::class);
        $senior = User::query()->where('mobile', '09121111111')->firstOrFail();
        $rep = User::query()->where('mobile', '09125555555')->firstOrFail();
        $roles = Role::query()->get()->keyBy('slug');

        $seniorNode = OrganizationNode::query()
            ->where('user_id', $senior->id)
            ->whereHas('role', fn ($q) => $q->where('slug', 'senior_manager'))
            ->firstOrFail();

        $child = $tree->attach($rep, $roles['representative'], $seniorNode, now()->toDateString());
        // attempt: move senior under its descendant
        $this->expectException(\InvalidArgumentException::class);
        $tree->reparent($seniorNode, $child);
    }

    public function test_database_integrity_checker_passes_on_seeded_demo(): void
    {
        $result = app(DatabaseIntegrityChecker::class)->run();
        $failed = collect($result['checks'])->where('ok', false)->pluck('name')->all();
        $this->assertTrue($result['ok'], 'Failed checks: '.implode(', ', $failed));
    }

    public function test_concurrent_identical_wallet_credits_are_idempotent(): void
    {
        $user = User::query()->where('mobile', '09125555555')->firstOrFail();
        $role = Role::query()->where('slug', 'representative')->firstOrFail();
        $wallet = app(WalletService::class)->walletFor($user, $role);
        $start = Money::normalize((string) $wallet->balance);

        // Simulate parallel workers with same key inside nested transactions
        $key = 'qa-race-credit-1';
        for ($i = 0; $i < 20; $i++) {
            DB::transaction(function () use ($wallet, $key) {
                app(WalletService::class)->credit($wallet->fresh(), '10.000', 'qa_credit', $key);
            });
        }

        $this->assertSame(1, WalletTransaction::query()->where('idempotency_key', $key)->count());
        $this->assertSame(
            Money::add($start, '10.000'),
            Money::normalize((string) $wallet->fresh()->balance)
        );
    }

    public function test_tampered_webhook_amount_does_not_credit_wrong_user_wallet(): void
    {
        $rep = User::query()->where('mobile', '09125555555')->firstOrFail();
        $outsider = User::query()->where('mobile', '09129999999')->firstOrFail();
        $role = Role::query()->where('slug', 'representative')->firstOrFail();
        $outWallet = app(WalletService::class)->walletFor($outsider, $role);
        $beforeOut = Money::normalize((string) $outWallet->balance);

        app(GatewaySaleService::class)->record([
            'external_id' => 'GW-QA-TAMPER-1',
            'name' => 'QA Tamper',
            'amount' => 100000,
            'representative_user_id' => $rep->id,
            'idempotency_key' => 'qa-tamper-sale-1',
            'status' => 'successful',
            'merchant_code' => 'fino-qa-tamper-0001',
        ]);

        $this->postJson('/api/webhooks/finopal/transaction', [
            'event' => 'transaction.verified',
            'merchant_id' => 'fino-qa-tamper-0001',
            'authority' => 'FP_QA_TAMPER_1',
            'amount' => 999999999,
            'profit' => 100000,
            'currency' => 'IRT',
            'status' => 'verified',
            'code' => 100,
            'metadata' => ['user_id' => $outsider->id],
        ], ['X-Finopal-Webhook-Secret' => 'test-webhook-secret'])->assertOk();

        $this->assertSame($beforeOut, Money::normalize((string) $outWallet->fresh()->balance));
        $this->assertSame(
            0,
            Commission::query()->where('user_id', $outsider->id)->where('idempotency_key', 'like', '%FP_QA_TAMPER%')->count()
        );
    }
}
