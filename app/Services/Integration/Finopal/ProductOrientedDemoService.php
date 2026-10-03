<?php

namespace App\Services\Integration\Finopal;

use App\Models\Commission;
use App\Models\FinopalTransaction;
use App\Models\ProductSale;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Seeds multi-product sales + commissions for visual QA (gateway + ticketing + custom).
 */
class ProductOrientedDemoService
{
    public function __construct(
        private readonly FinopalWebhookDemoService $gateways,
        private readonly FinopalTransactionService $transactions,
    ) {}

    /**
     * @return array{
     *   gateway_txs: int,
     *   product_txs: int,
     *   commissions: int,
     *   product_sales: int,
     *   users: array<string, array{mobile:string,national_id:string}>
     * }
     */
    public function seed(bool $resetGatewayFinance = true): array
    {
        $prepared = $this->gateways->prepare(false, $resetGatewayFinance);
        $users = $this->ensureIdentities();
        if ($resetGatewayFinance) {
            $this->resetProductDemoFinance();
        }

        // Gateway profits across merchants (all roles via tree/share)
        $gatewayPayloads = [
            ['merchant_id' => 'fino-demo-tree-0001', 'authority' => 'FP_PROD_TREE_A', 'amount' => 2_000_000, 'profit' => 200_000],
            ['merchant_id' => 'fino-demo-tree-0001', 'authority' => 'FP_PROD_TREE_B', 'amount' => 1_500_000, 'profit' => 150_000],
            ['merchant_id' => 'fino-seed-solo-0001', 'authority' => 'FP_PROD_SOLO_A', 'amount' => 800_000, 'profit' => 80_000],
            ['merchant_id' => 'fino-seed-solo-0001', 'authority' => 'FP_PROD_SOLO_B', 'amount' => 900_000, 'profit' => 90_000],
            ['merchant_id' => 'fino-seed-share-0001', 'authority' => 'FP_PROD_SHARE_A', 'amount' => 1_200_000, 'profit' => 120_000],
            ['merchant_id' => 'fino-seed-share-0001', 'authority' => 'FP_PROD_SHARE_B', 'amount' => 1_400_000, 'profit' => 140_000],
            ['merchant_id' => 'fino-seed-multib-0001', 'authority' => 'FP_PROD_MULTI_A', 'amount' => 700_000, 'profit' => 70_000],
        ];

        $gatewayTxs = 0;
        foreach ($gatewayPayloads as $row) {
            $tx = $this->ingestGateway($row);
            if (! $tx->getAttribute('was_duplicate')) {
                $gatewayTxs++;
            }
        }

        // Ticketing + a third custom product — owners cover main rep, share partners, multi-role
        $productPayloads = [
            [
                'product_type' => 'ticketing',
                'product_code' => 'FINOPAL-TICKETING',
                'title' => 'خرید تیکتینگ — نماینده اصلی',
                'owner_national_id' => $users['rep']['national_id'],
                'external_sale_id' => 'TICKET-DEMO-REP-1',
                'authority' => 'FP_TICKET_REP_1',
                'amount' => 5_000_000,
                'profit' => 500_000,
            ],
            [
                'product_type' => 'ticketing',
                'product_code' => 'FINOPAL-TICKETING',
                'title' => 'خرید تیکتینگ — نماینده اصلی (۲)',
                'owner_national_id' => $users['rep']['national_id'],
                'external_sale_id' => 'TICKET-DEMO-REP-2',
                'authority' => 'FP_TICKET_REP_2',
                'amount' => 3_200_000,
                'profit' => 320_000,
            ],
            [
                'product_type' => 'ticketing',
                'product_code' => 'FINOPAL-TICKETING',
                'title' => 'خرید تیکتینگ — اشتراکی الف',
                'owner_national_id' => $users['share_a']['national_id'],
                'external_sale_id' => 'TICKET-DEMO-SHA-1',
                'authority' => 'FP_TICKET_SHA_1',
                'amount' => 2_500_000,
                'profit' => 250_000,
            ],
            [
                'product_type' => 'ticketing',
                'product_code' => 'FINOPAL-TICKETING',
                'title' => 'خرید تیکتینگ — چندنقشی ب',
                'owner_national_id' => $users['multi_b']['national_id'],
                'external_sale_id' => 'TICKET-DEMO-MB-1',
                'authority' => 'FP_TICKET_MB_1',
                'amount' => 4_100_000,
                'profit' => 410_000,
            ],
            [
                'product_type' => 'subscription',
                'product_code' => 'FINOPAL-SUB-PRO',
                'title' => 'اشتراک حرفه‌ای فاینوپال',
                'owner_national_id' => $users['rep']['national_id'],
                'external_sale_id' => 'SUB-DEMO-REP-1',
                'authority' => 'FP_SUB_REP_1',
                'amount' => 1_800_000,
                'profit' => 180_000,
            ],
            [
                'product_type' => 'subscription',
                'product_code' => 'FINOPAL-SUB-PRO',
                'title' => 'اشتراک حرفه‌ای — مدیر فروش',
                'owner_national_id' => $users['sales']['national_id'],
                'external_sale_id' => 'SUB-DEMO-SM-1',
                'authority' => 'FP_SUB_SM_1',
                'amount' => 2_200_000,
                'profit' => 220_000,
            ],
            [
                'product_type' => 'ticketing',
                'product_code' => 'FINOPAL-TICKETING',
                'title' => 'تیکتینگ اشتراکی ۵۰-۵۰',
                'owners' => [
                    ['national_id' => $users['share_a']['national_id'], 'share_percent' => 50],
                    ['national_id' => $users['share_b']['national_id'], 'share_percent' => 50],
                ],
                'external_sale_id' => 'TICKET-DEMO-SHARE-50',
                'authority' => 'FP_TICKET_SHARE_50',
                'amount' => 6_000_000,
                'profit' => 600_000,
            ],
        ];

        $productTxs = 0;
        foreach ($productPayloads as $row) {
            $tx = $this->ingestProduct($row);
            if (! $tx->getAttribute('was_duplicate')) {
                $productTxs++;
            }
        }

        return [
            'gateway_merchants' => $prepared['merchants'],
            'gateway_txs' => $gatewayTxs,
            'product_txs' => $productTxs,
            'commissions' => Commission::query()->count(),
            'product_sales' => ProductSale::query()->count(),
            'transactions' => FinopalTransaction::query()->count(),
            'users' => $users,
        ];
    }

    /** @param  array<string, mixed>  $row */
    private function ingestGateway(array $row): FinopalTransaction
    {
        return $this->transactions->ingest([
            'event' => 'transaction.verified',
            'product_type' => 'gateway_profit',
            'merchant_id' => $row['merchant_id'],
            'authority' => $row['authority'],
            'amount' => $row['amount'],
            'profit' => $row['profit'],
            'currency' => 'IRT',
            'status' => 'OK',
            'code' => 100,
            'paid_at' => now()->subDays(random_int(0, 20))->toIso8601String(),
        ]);
    }

    /** @param  array<string, mixed>  $row */
    private function ingestProduct(array $row): FinopalTransaction
    {
        $payload = [
            'event' => 'transaction.verified',
            'product_type' => $row['product_type'],
            'product_code' => $row['product_code'],
            'title' => $row['title'],
            'external_sale_id' => $row['external_sale_id'],
            'authority' => $row['authority'],
            'amount' => $row['amount'],
            'profit' => $row['profit'],
            'currency' => 'IRT',
            'status' => 'OK',
            'code' => 100,
            'paid_at' => now()->subDays(random_int(0, 15))->toIso8601String(),
        ];
        if (! empty($row['owners'])) {
            $payload['owners'] = $row['owners'];
        } else {
            $payload['owner_national_id'] = $row['owner_national_id'];
        }

        return $this->transactions->ingest($payload);
    }

    /** پاک‌سازی تراکنش/فروش/پورسانت دموی محصول بدون صفر کردن کل کیف. */
    private function resetProductDemoFinance(): void
    {
        $authorities = [
            'FP_TICKET_REP_1', 'FP_TICKET_REP_2', 'FP_TICKET_SHA_1', 'FP_TICKET_MB_1',
            'FP_SUB_REP_1', 'FP_SUB_SM_1', 'FP_TICKET_SHARE_50',
        ];

        DB::transaction(function () use ($authorities) {
            $txs = FinopalTransaction::query()->whereIn('authority', $authorities)->get(['id', 'product_sale_id']);
            if ($txs->isEmpty()) {
                return;
            }

            $txIds = $txs->pluck('id');
            $saleIds = $txs->pluck('product_sale_id')->filter()->unique()->values();
            $commissionIds = Commission::query()
                ->where(function ($q) use ($saleIds, $txIds) {
                    $q->whereIn('finopal_transaction_id', $txIds);
                    if ($saleIds->isNotEmpty()) {
                        $q->orWhereIn('product_sale_id', $saleIds);
                    }
                })
                ->pluck('id');

            if ($commissionIds->isNotEmpty()) {
                $walletTxs = WalletTransaction::query()
                    ->where('reference_type', Commission::class)
                    ->whereIn('reference_id', $commissionIds)
                    ->get(['id', 'wallet_id', 'type', 'amount']);

                foreach ($walletTxs->groupBy('wallet_id') as $walletId => $rows) {
                    $wallet = Wallet::query()->whereKey($walletId)->lockForUpdate()->first();
                    if (! $wallet) {
                        continue;
                    }
                    $remove = '0.000';
                    foreach ($rows as $row) {
                        if ($row->type === 'commission_credit') {
                            $remove = Money::add($remove, (string) $row->amount);
                        }
                    }
                    if (Money::cmp($remove, '0') > 0) {
                        $next = Money::sub((string) $wallet->balance, $remove);
                        $wallet->balance = Money::cmp($next, '0') < 0 ? '0.000' : $next;
                        $wallet->save();
                    }
                }

                WalletTransaction::query()->whereIn('id', $walletTxs->pluck('id'))->delete();
                Commission::query()->whereIn('id', $commissionIds)->delete();
            }

            FinopalTransaction::query()->whereIn('id', $txIds)->update(['product_sale_id' => null]);
            FinopalTransaction::query()->whereIn('id', $txIds)->delete();
            if ($saleIds->isNotEmpty()) {
                ProductSale::query()->whereIn('id', $saleIds)->delete();
            }

            // پاک‌سازی فروش‌های یتیم دمو (از ریست‌های ناقص قبلی)
            ProductSale::query()
                ->whereIn('external_ref', [
                    'TICKET-DEMO-REP-1', 'TICKET-DEMO-REP-2', 'TICKET-DEMO-SHA-1', 'TICKET-DEMO-MB-1',
                    'SUB-DEMO-REP-1', 'SUB-DEMO-SM-1', 'TICKET-DEMO-SHARE-50',
                ])
                ->delete();
        });
    }

    /** @return array<string, array{mobile:string,national_id:string}> */
    private function ensureIdentities(): array
    {
        $map = [
            'rep' => ['mobile' => '09125555555', 'national_id' => '0010000001'],
            'referrer' => ['mobile' => '09124444444', 'national_id' => '0010000002'],
            'sales' => ['mobile' => '09123333333', 'national_id' => '0010000003'],
            'dev' => ['mobile' => '09122222222', 'national_id' => '0010000004'],
            'senior' => ['mobile' => '09121111111', 'national_id' => '0010000005'],
            'share_a' => ['mobile' => '09127777777', 'national_id' => '0010000006'],
            'share_b' => ['mobile' => '09128888888', 'national_id' => '0010000007'],
            'multi_b' => ['mobile' => '09120202020', 'national_id' => '0010000008'],
        ];

        $out = [];
        DB::transaction(function () use ($map, &$out) {
            foreach ($map as $key => $row) {
                $user = User::query()->where('mobile', $row['mobile'])->first();
                if (! $user) {
                    throw new \RuntimeException("کاربر دمو {$row['mobile']} پیدا نشد. ابتدا DatabaseSeeder را اجرا کنید.");
                }
                // Avoid unique collisions if another user already holds this national_id.
                User::query()
                    ->where('national_id', $row['national_id'])
                    ->where('id', '!=', $user->id)
                    ->update(['national_id' => null]);
                $user->forceFill([
                    'national_id' => $row['national_id'],
                    'is_active' => true,
                ])->save();
                $out[$key] = $row;
            }
        });

        return $out;
    }
}
