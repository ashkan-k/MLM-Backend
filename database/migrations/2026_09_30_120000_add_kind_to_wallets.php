<?php

use App\Models\Wallet;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('wallets', 'kind')) {
            Schema::table('wallets', function (Blueprint $table) {
                $table->string('kind', 32)->default(Wallet::KIND_ROLE)->after('role_id');
            });
        }

        $indexNames = collect(Schema::getIndexes('wallets'))->pluck('name');

        // MySQL uses the composite unique as the user_id foreign-key index.
        // A standalone index must exist before that unique can be dropped.
        if (! $indexNames->contains('wallets_user_id_foreign') && ! $indexNames->contains('wallets_user_id_index')) {
            Schema::table('wallets', function (Blueprint $table) {
                $table->index('user_id', 'wallets_user_id_foreign');
            });
        }

        $indexNames = collect(Schema::getIndexes('wallets'))->pluck('name');
        if ($indexNames->contains('wallets_user_id_role_id_currency_unique')) {
            Schema::table('wallets', function (Blueprint $table) {
                $table->dropUnique(['user_id', 'role_id', 'currency']);
            });
        }

        $indexNames = collect(Schema::getIndexes('wallets'))->pluck('name');
        if (! $indexNames->contains('wallets_user_id_role_id_currency_kind_unique')) {
            Schema::table('wallets', function (Blueprint $table) {
                $table->unique(['user_id', 'role_id', 'currency', 'kind']);
            });
        }

        $this->splitHistoricalResiduals();
    }

    public function down(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'role_id', 'currency', 'kind']);
            $table->unique(['user_id', 'role_id', 'currency']);
            $table->dropColumn('kind');
        });
    }

    /**
     * Move already-posted residual bonus ledger lines off the senior role wallet
     * so personal commissions and leftover monthly bonuses are not mixed.
     */
    private function splitHistoricalResiduals(): void
    {
        $txTable = 'wallet_transactions';
        $rows = DB::table($txTable)
            ->where(function ($q) {
                $q->where('type', 'monthly_bonus_residual')
                    ->orWhere(function ($inner) {
                        $inner->where('type', 'monthly_bonus_reversal')
                            ->where('idempotency_key', 'like', '%monthly-bonus-residual%');
                    });
            })
            ->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        $byWallet = $rows->groupBy('wallet_id');
        foreach ($byWallet as $sourceId => $txs) {
            $source = DB::table('wallets')->where('id', $sourceId)->first();
            if (! $source || ($source->kind ?? Wallet::KIND_ROLE) === Wallet::KIND_BONUS_RESIDUAL) {
                continue;
            }

            $residualId = DB::table('wallets')
                ->where('user_id', $source->user_id)
                ->where('role_id', $source->role_id)
                ->where('currency', $source->currency)
                ->where('kind', Wallet::KIND_BONUS_RESIDUAL)
                ->value('id');

            if (! $residualId) {
                $residualId = DB::table('wallets')->insertGetId([
                    'user_id' => $source->user_id,
                    'role_id' => $source->role_id,
                    'kind' => Wallet::KIND_BONUS_RESIDUAL,
                    'currency' => $source->currency,
                    'balance' => '0.000',
                    'held_balance' => '0.000',
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table($txTable)->whereIn('id', $txs->pluck('id')->all())->update([
                'wallet_id' => $residualId,
            ]);

            $this->rewriteBalanceChain((int) $residualId);
            $this->rewriteBalanceChain((int) $sourceId);
        }
    }

    private function rewriteBalanceChain(int $walletId): void
    {
        $running = '0.000';
        $txs = DB::table('wallet_transactions')->where('wallet_id', $walletId)->orderBy('id')->get();
        foreach ($txs as $tx) {
            $before = $this->normalize($running);
            $delta = bcsub($this->normalize((string) $tx->balance_after), $this->normalize((string) $tx->balance_before), 3);
            $after = bcadd($before, $delta, 3);
            DB::table('wallet_transactions')->where('id', $tx->id)->update([
                'balance_before' => $before,
                'balance_after' => $after,
            ]);
            $running = $after;
        }

        DB::table('wallets')->where('id', $walletId)->update([
            'balance' => $this->normalize($running),
            'updated_at' => now(),
        ]);
    }

    private function normalize(string $amount): string
    {
        if (! is_numeric($amount)) {
            return '0.000';
        }

        return bcadd($amount, '0', 3);
    }
};
