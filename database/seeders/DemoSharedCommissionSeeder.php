<?php

namespace Database\Seeders;

use App\Models\Gateway;
use App\Models\GatewaySale;
use App\Models\SharedLink;
use App\Models\User;
use App\Services\Gateway\GatewaySaleService;
use App\Services\Integration\Finopal\FinopalTransactionService;
use App\Services\Referral\SharedLinkService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Demo commissions for:
 * - new rep 09163333333 (shared referral 50/50 of share_a & share_b)
 * - shared gateway between share_a & share_b
 *
 * Safe to re-run (idempotent keys).
 */
class DemoSharedCommissionSeeder extends Seeder
{
    public function run(): void
    {
        $sales = app(GatewaySaleService::class);
        $transactions = app(FinopalTransactionService::class);

        $newbie = User::query()->where('mobile', '09163333333')->first();
        $shareA = User::query()->where('mobile', '09127777777')->first();
        $shareB = User::query()->where('mobile', '09128888888')->first();

        if ($newbie) {
            $merchant = 'fino-demo-newrep-09163333333';
            $sale = $sales->record([
                'external_id' => 'GW-DEMO-NEWREP-09163333333',
                'name' => 'درگاه تستی نماینده جدید (اشتراک معرف)',
                'amount' => 1500000,
                'representative_user_id' => $newbie->id,
                'customer' => [
                    'name' => 'مشتری تست نماینده جدید',
                    'mobile' => '09160001111',
                    'national_id' => '0012345678',
                    'sheba' => 'IR120170000000111111111001',
                ],
                'idempotency_key' => 'demo-newrep-gateway-09163333333',
                'status' => 'successful',
                'merchant_code' => $merchant,
            ]);
            $sale->gateway?->update(['merchant_code' => $merchant, 'is_active' => true]);

            foreach ([
                ['authority' => 'FP_DEMO_NEWREP_1', 'amount' => 800000, 'profit' => 800000],
                ['authority' => 'FP_DEMO_NEWREP_2', 'amount' => 1200000, 'profit' => 1200000],
                ['authority' => 'FP_DEMO_NEWREP_3', 'amount' => 500000, 'profit' => 500000],
            ] as $tx) {
                $transactions->ingest([
                    'event' => 'transaction.verified',
                    'merchant_id' => $merchant,
                    'authority' => $tx['authority'],
                    'amount' => $tx['amount'],
                    'profit' => $tx['profit'],
                    'currency' => 'IRT',
                    'status' => 'verified',
                    'code' => 100,
                ]);
            }

            $this->command?->info("Gateway + txs for {$newbie->mobile} ready (merchant {$merchant}).");
        } else {
            $this->command?->warn('User 09163333333 not found — skipped newbie demo gateway.');
        }

        if ($shareA && $shareB) {
            $merchant = 'fino-demo-share-ab-50-50';
            $existing = Gateway::query()->where('merchant_code', $merchant)->first()
                ?? Gateway::query()->where('external_id', 'GW-DEMO-SHARE-AB-50')->first();

            if (! $existing) {
                $link = SharedLink::query()
                    ->where('type', 'gateway_sale')
                    ->where('status', 'active')
                    ->whereHas('members', fn ($q) => $q->where('user_id', $shareA->id))
                    ->whereHas('members', fn ($q) => $q->where('user_id', $shareB->id))
                    ->latest('id')
                    ->first();

                if (! $link) {
                    $link = app(SharedLinkService::class)->create($shareA, 'gateway_sale', [
                        ['user_id' => $shareA->id, 'share_percent' => '50.000'],
                        ['user_id' => $shareB->id, 'share_percent' => '50.000'],
                    ]);
                    app(SharedLinkService::class)->approve($shareB, $link);
                }

                $sale = $sales->record([
                    'external_id' => 'GW-DEMO-SHARE-AB-50',
                    'name' => 'درگاه اشتراکی تستی الف/ب ۵۰-۵۰',
                    'amount' => 2500000,
                    'shared_link_id' => $link->id,
                    'customer' => [
                        'name' => 'مشتری تست اشتراکی الف‌ب',
                        'mobile' => '09160002222',
                        'national_id' => '0012345679',
                        'sheba' => 'IR120170000000111111111002',
                    ],
                    'idempotency_key' => 'demo-share-ab-gateway-50',
                    'status' => 'successful',
                    'merchant_code' => $merchant,
                ]);
                $sale->gateway?->update(['merchant_code' => $merchant, 'is_active' => true]);
            } else {
                $existing->update(['merchant_code' => $merchant, 'is_active' => true]);
                GatewaySale::query()
                    ->where('gateway_id', $existing->id)
                    ->where('status', '!=', 'successful')
                    ->update(['status' => 'successful']);
            }

            // Also activate any pending shared sale between A/B that user may have submitted from UI.
            $pendingShared = GatewaySale::query()
                ->whereHas('representatives', fn ($q) => $q->where('user_id', $shareA->id))
                ->whereHas('representatives', fn ($q) => $q->where('user_id', $shareB->id))
                ->where('status', '!=', 'successful')
                ->latest('id')
                ->first();
            if ($pendingShared) {
                $code = $pendingShared->gateway?->merchant_code ?: ('fino-ui-share-'.Str::lower(Str::random(8)));
                $pendingShared->gateway?->update(['merchant_code' => $code, 'is_active' => true]);
                $pendingShared->update(['status' => 'successful']);
                foreach ([
                    ['authority' => 'FP_UI_SHARE_'.$pendingShared->id.'_1', 'amount' => 900000, 'profit' => 900000],
                    ['authority' => 'FP_UI_SHARE_'.$pendingShared->id.'_2', 'amount' => 1100000, 'profit' => 1100000],
                ] as $tx) {
                    $transactions->ingest([
                        'event' => 'transaction.verified',
                        'merchant_id' => $code,
                        'authority' => $tx['authority'],
                        'amount' => $tx['amount'],
                        'profit' => $tx['profit'],
                        'currency' => 'IRT',
                        'status' => 'verified',
                        'code' => 100,
                    ]);
                }
                $this->command?->info("Activated UI shared sale #{$pendingShared->id} with txs (merchant {$code}).");
            }

            foreach ([
                ['authority' => 'FP_DEMO_SHARE_AB_1', 'amount' => 1000000, 'profit' => 1000000],
                ['authority' => 'FP_DEMO_SHARE_AB_2', 'amount' => 1500000, 'profit' => 1500000],
                ['authority' => 'FP_DEMO_SHARE_AB_3', 'amount' => 750000, 'profit' => 750000],
            ] as $tx) {
                $transactions->ingest([
                    'event' => 'transaction.verified',
                    'merchant_id' => $merchant,
                    'authority' => $tx['authority'],
                    'amount' => $tx['amount'],
                    'profit' => $tx['profit'],
                    'currency' => 'IRT',
                    'status' => 'verified',
                    'code' => 100,
                ]);
            }

            $this->command?->info("Shared A/B gateway txs ready (merchant {$merchant}).");
        } else {
            $this->command?->warn('share_a / share_b not found — skipped shared demo gateway.');
        }
    }
}
