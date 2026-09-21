<?php

namespace App\Services\Integration\Finopal;

use App\Models\Commission;
use App\Models\FinopalTransaction;
use App\Models\Gateway;
use App\Models\GatewaySale;
use App\Models\OrganizationNode;
use App\Models\ReferralCode;
use App\Models\Role;
use App\Models\SharedLink;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Commission\CommissionCalculator;
use App\Services\Commission\CommissionRuleResolver;
use App\Services\Gateway\GatewaySaleService;
use App\Services\Organization\OrganizationTreeService;
use App\Services\Referral\SharedLinkService;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FinopalWebhookDemoService
{
    /** @var array<string, array{external_id: string, idempotency_key: string, name: string, type: string}> */
    public const MERCHANTS = [
        'fino-seed-solo-0001' => [
            'external_id' => 'GW-SOLO-1',
            'idempotency_key' => 'seed-solo-1',
            'name' => 'درگاه انفرادی نماینده',
            'type' => 'solo',
        ],
        'fino-seed-share-0001' => [
            'external_id' => 'GW-SHARE-1',
            'idempotency_key' => 'seed-share-1',
            'name' => 'درگاه اشتراکی ۵۰-۵۰',
            'type' => 'shared',
        ],
        'fino-seed-multib-0001' => [
            'external_id' => 'GW-MULTI-B-1',
            'idempotency_key' => 'demo-multi-b-gateway-1',
            'name' => 'درگاه کاربر چندنقشی ب',
            'type' => 'multib',
        ],
        'fino-demo-tree-0001' => [
            'external_id' => 'GW-DEMO-TREE-1',
            'idempotency_key' => 'finopal-demo-tree-1',
            'name' => 'درگاه تست درخت کامل (انفرادی)',
            'type' => 'tree',
        ],
    ];

    public const DEFAULT_PROFIT_IRT = '100000';

    public function __construct(
        private readonly GatewaySaleService $sales,
        private readonly CommissionCalculator $calculator,
        private readonly CommissionRuleResolver $rules,
    ) {}

    public function prepare(bool $withSampleTransaction = false, bool $reset = true): array
    {
        $users = $this->requireUsers();
        $sales = $this->ensureAllGateways($users);
        if ($reset) {
            $this->resetFinancialData($sales);
        }

        $expected = [];
        foreach ($sales as $merchant => $sale) {
            $expected[$merchant] = $this->expectedBreakdown($sale, self::DEFAULT_PROFIT_IRT);
        }

        $sample = null;
        if ($withSampleTransaction) {
            $sample = app(FinopalTransactionService::class)->ingest([
                'event' => 'transaction.verified',
                'merchant_id' => 'fino-demo-tree-0001',
                'authority' => 'FP_DEMO_TREE_SEED',
                'amount' => 1000000,
                'profit' => self::DEFAULT_PROFIT_IRT,
                'currency' => 'IRT',
                'status' => 'OK',
                'code' => 100,
            ]);
        }

        return [
            'merchants' => array_keys(self::MERCHANTS),
            'sales' => collect($sales)->map(fn (GatewaySale $s) => [
                'id' => $s->id,
                'external_id' => $s->gateway?->external_id,
                'merchant_code' => $s->gateway?->merchant_code,
            ])->all(),
            'expected_profit_base' => self::DEFAULT_PROFIT_IRT,
            'expected' => $expected,
            'sample_transaction' => $sample ? [
                'id' => $sample->id,
                'merchant_id' => 'fino-demo-tree-0001',
                'commissions' => $sample->commissions->count(),
            ] : null,
        ];
    }

    /** @return array<string, GatewaySale> */
    private function ensureAllGateways(array $users): array
    {
        $out = [];

        $out['fino-seed-solo-0001'] = $this->finalizeSale($this->sales->record([
            'external_id' => 'GW-SOLO-1',
            'name' => 'درگاه انفرادی نماینده',
            'amount' => 1000000,
            'representative_user_id' => $users['rep']->id,
            'customer' => ['name' => 'مشتری یک', 'mobile' => '09121230001'],
            'idempotency_key' => 'seed-solo-1',
            'status' => 'successful',
            'merchant_code' => 'fino-seed-solo-0001',
        ]), 'fino-seed-solo-0001');

        $link = SharedLink::query()
            ->where('status', 'active')
            ->whereHas('members', fn ($q) => $q->where('user_id', $users['share_a']->id))
            ->whereHas('members', fn ($q) => $q->where('user_id', $users['share_b']->id))
            ->first();

        if (! $link) {
            $link = app(SharedLinkService::class)->create($users['share_a'], 'gateway_sale', [
                ['user_id' => $users['share_a']->id, 'share_percent' => '50.000'],
                ['user_id' => $users['share_b']->id, 'share_percent' => '50.000'],
            ]);
            app(SharedLinkService::class)->approve($users['share_b'], $link);
        }

        $out['fino-seed-share-0001'] = $this->finalizeSale($this->sales->record([
            'external_id' => 'GW-SHARE-1',
            'name' => 'درگاه اشتراکی ۵۰-۵۰',
            'amount' => 2000000,
            'shared_link_id' => $link->id,
            'customer' => ['name' => 'مشتری اشتراکی', 'mobile' => '09121230002'],
            'idempotency_key' => 'seed-share-1',
            'status' => 'successful',
            'merchant_code' => 'fino-seed-share-0001',
        ]), 'fino-seed-share-0001');

        $this->ensureRepresentativeNode(
            $users['multi_b'],
            OrganizationNode::query()
                ->where('user_id', $users['sales']->id)
                ->where('is_active', true)
                ->whereHas('role', fn ($q) => $q->where('slug', 'sales_manager'))
                ->first(),
            Role::query()->where('slug', 'representative')->firstOrFail(),
        );

        $this->dropStaleMultibSale($users['multi_b']);

        $out['fino-seed-multib-0001'] = $this->finalizeSale($this->sales->record([
            'external_id' => 'GW-MULTI-B-1',
            'name' => 'درگاه کاربر چندنقشی ب',
            'amount' => 1800000,
            'representative_user_id' => $users['multi_b']->id,
            'customer' => ['name' => 'مشتری انتقال مزایا', 'mobile' => '09121230077'],
            'idempotency_key' => 'finopal-webhook-demo-multib-1',
            'status' => 'successful',
            'merchant_code' => 'fino-seed-multib-0001',
        ]), 'fino-seed-multib-0001');

        $out['fino-demo-tree-0001'] = $this->finalizeSale($this->sales->record([
            'external_id' => 'GW-DEMO-TREE-1',
            'name' => 'درگاه تست درخت کامل (انفرادی)',
            'amount' => 1500000,
            'representative_user_id' => $users['rep']->id,
            'customer' => [
                'name' => 'مشتری تست درخت',
                'mobile' => '09121230999',
                'national_id' => '0012345999',
                'sheba' => 'IR120170000000123456789999',
                'province' => 'تهران',
                'city' => 'تهران',
            ],
            'idempotency_key' => 'finopal-demo-tree-1',
            'status' => 'successful',
            'merchant_code' => 'fino-demo-tree-0001',
        ]), 'fino-demo-tree-0001');

        return $out;
    }

    private function finalizeSale(GatewaySale $sale, string $merchantCode): GatewaySale
    {
        Gateway::query()->whereKey($sale->gateway_id)->update([
            'merchant_code' => $merchantCode,
            'is_active' => true,
        ]);
        $sale->update(['status' => 'successful']);

        return $sale->fresh([
            'gateway',
            'representatives.user',
            'referrers.user',
            'managers.user',
            'managers.role',
        ]);
    }

    /** @param array<string, GatewaySale> $sales */
    private function resetFinancialData(array $sales): void
    {
        $gatewayIds = Gateway::query()
            ->whereIn('merchant_code', array_keys(self::MERCHANTS))
            ->pluck('id');

        if ($gatewayIds->isEmpty()) {
            return;
        }

        $saleIds = GatewaySale::query()->whereIn('gateway_id', $gatewayIds)->pluck('id');
        $stakeholderWallets = $this->stakeholderWalletIds(collect($sales));

        DB::transaction(function () use ($gatewayIds, $saleIds, $stakeholderWallets) {
            $txIds = FinopalTransaction::query()->whereIn('gateway_id', $gatewayIds)->pluck('id');
            $commissionIds = Commission::query()
                ->where(function ($q) use ($saleIds, $txIds) {
                    $q->whereIn('gateway_sale_id', $saleIds);
                    if ($txIds->isNotEmpty()) {
                        $q->orWhereIn('finopal_transaction_id', $txIds);
                    }
                })
                ->pluck('id');

            if ($commissionIds->isNotEmpty()) {
                WalletTransaction::query()
                    ->where('reference_type', Commission::class)
                    ->whereIn('reference_id', $commissionIds)
                    ->delete();
                Commission::query()->whereIn('id', $commissionIds)->delete();
            }

            FinopalTransaction::query()->whereIn('gateway_id', $gatewayIds)->delete();

            if ($stakeholderWallets->isNotEmpty()) {
                WalletTransaction::query()->whereIn('wallet_id', $stakeholderWallets)->delete();
                Wallet::query()->whereIn('id', $stakeholderWallets)->update([
                    'balance' => '0.000',
                    'held_balance' => '0.000',
                ]);
            }
        });
    }

    /** @param Collection<string, GatewaySale> $sales */
    private function stakeholderWalletIds(Collection $sales): Collection
    {
        $walletIds = collect();

        foreach ($sales as $sale) {
            foreach ($sale->representatives as $row) {
                $walletIds = $walletIds->merge($this->walletIdFor($row->user_id, 'representative'));
            }
            foreach ($sale->referrers as $row) {
                $walletIds = $walletIds->merge($this->walletIdFor($row->user_id, 'representative_referrer'));
            }
            foreach ($sale->managers as $row) {
                $slug = $row->role?->slug;
                if ($slug) {
                    $walletIds = $walletIds->merge($this->walletIdFor($row->user_id, $slug));
                }
            }
        }

        return $walletIds->unique()->filter();
    }

    private function walletIdFor(int $userId, string $roleSlug): ?int
    {
        $roleId = Role::query()->where('slug', $roleSlug)->value('id');
        if (! $roleId) {
            return null;
        }

        return Wallet::query()->where('user_id', $userId)->where('role_id', $roleId)->value('id');
    }

    public function expectedBreakdown(GatewaySale $sale, string $profitBase): array
    {
        $sale->loadMissing(['representatives.user', 'referrers.user', 'managers.user', 'managers.role']);
        $at = now();
        $rows = [];

        foreach ($sale->representatives as $row) {
            $share = (string) $row->share_percent;
            $percent = $this->resolvedSharedPercent('representative', $share, $at);
            $rows[] = [
                'user' => $row->user?->name,
                'mobile' => $row->user?->mobile,
                'role_slug' => 'representative',
                'role_label' => 'نماینده',
                'percent' => Money::normalize($percent, 3),
                'share_note' => $share,
                'base' => Money::normalize($profitBase, 3),
                'amount' => Money::normalize(Money::percentOf($profitBase, $percent), 3),
            ];
        }

        // Mirror engine: 2% of total profit once per unique primary referrer
        $refRate = $this->resolvedPercent('representative_referrer', $at);
        $pool = Money::percentOf($profitBase, $refRate);
        $seenPrimary = [];
        $refTotals = [];
        foreach ($sale->representatives as $row) {
            $referral = \App\Models\RepresentativeReferral::query()
                ->with('shareMembers')
                ->where('referred_user_id', $row->user_id)
                ->first();
            if (! $referral) {
                continue;
            }
            $primaryId = (int) $referral->referrer_user_id;
            if (isset($seenPrimary[$primaryId])) {
                continue;
            }
            $seenPrimary[$primaryId] = true;
            $members = $referral->shareMembers->isNotEmpty()
                ? $referral->shareMembers
                : collect([(object) ['user_id' => $primaryId, 'share_percent' => '100.000']]);
            foreach ($members as $member) {
                $mid = (int) $member->user_id;
                $part = Money::percentOf($pool, (string) $member->share_percent);
                $refTotals[$mid] = Money::add($refTotals[$mid] ?? '0.000', $part);
            }
        }
        foreach ($refTotals as $userId => $amount) {
            $user = User::query()->find($userId);
            $rows[] = [
                'user' => $user?->name,
                'mobile' => $user?->mobile,
                'role_slug' => 'representative_referrer',
                'role_label' => 'نماینده معرف',
                'percent' => Money::normalize($refRate, 3),
                'share_note' => '100.000',
                'base' => Money::normalize($profitBase, 3),
                'amount' => Money::normalize($amount, 3),
            ];
        }

        foreach ($sale->managers as $row) {
            $slug = $row->role?->slug ?? 'manager';
            $percent = $this->resolvedPercent($slug, $at);
            $rows[] = $this->row($row->user, $slug, $row->role?->name ?? $slug, $percent, $profitBase);
        }

        $total = collect($rows)->reduce(fn ($c, $r) => Money::add($c ?? '0.000', $r['amount']), '0.000');

        return [
            'gateway' => $sale->gateway?->external_id,
            'merchant_code' => $sale->gateway?->merchant_code,
            'profit_base' => Money::normalize($profitBase, 3),
            'rows' => $rows,
            'total_commission' => $total,
            'total_percent_of_profit' => Money::normalize(bcmul(bcdiv($total, $profitBase, 6), '100', 3), 3),
        ];
    }

    private function row(?User $user, string $roleSlug, string $roleLabel, string $percent, string $base, ?string $shareNote = null): array
    {
        return [
            'user' => $user?->name,
            'mobile' => $user?->mobile,
            'role_slug' => $roleSlug,
            'role_label' => $roleLabel,
            'percent' => Money::normalize($percent, 3),
            'share_note' => $shareNote,
            'amount' => $this->calculator->amount($base, $percent),
        ];
    }

    private function resolvedPercent(string $roleSlug, mixed $at): string
    {
        $version = $this->rules->resolve($roleSlug, $at);

        return $version ? Money::normalize((string) $version->percent) : '0.000';
    }

    private function resolvedSharedPercent(string $roleSlug, string $sharePercent, mixed $at): string
    {
        return $this->calculator->sharedPercent(
            $this->resolvedPercent($roleSlug, $at),
            $sharePercent
        );
    }

    private function ensureRepresentativeNode(User $user, ?OrganizationNode $parent, Role $repRole): void
    {
        if (! $parent) {
            return;
        }

        $exists = OrganizationNode::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->whereHas('role', fn ($q) => $q->where('slug', 'representative'))
            ->exists();

        if (! $exists) {
            app(OrganizationTreeService::class)->attach(
                $user,
                $repRole,
                $parent,
                now()->subMonths(5)->toDateString()
            );
        }
    }

    private function dropStaleMultibSale(User $multiB): void
    {
        $gateway = Gateway::query()->where('external_id', 'GW-MULTI-B-1')->first();
        if (! $gateway) {
            return;
        }

        GatewaySale::query()
            ->where('gateway_id', $gateway->id)
            ->with('representatives')
            ->get()
            ->each(function (GatewaySale $sale) use ($multiB) {
                $repId = $sale->representatives->first()?->user_id;
                if ($repId !== null && $repId !== $multiB->id) {
                    $sale->representatives()->delete();
                    $sale->referrers()->delete();
                    $sale->managers()->delete();
                    $sale->delete();
                }
            });
    }

    /** @return array<string, User> */
    private function requireUsers(): array
    {
        $map = [
            'rep' => '09125555555',
            'referrer' => '09124444444',
            'sales' => '09123333333',
            'dev' => '09122222222',
            'senior' => '09121111111',
            'share_a' => '09127777777',
            'share_b' => '09128888888',
            'multi_b' => '09120202020',
        ];

        $out = [];
        foreach ($map as $key => $mobile) {
            $user = User::query()->where('mobile', $mobile)->first();
            if (! $user) {
                throw new \RuntimeException(
                    "کاربر دمو {$mobile} پیدا نشد. یک‌بار DatabaseSeeder را اجرا کنید: php artisan db:seed --class=DatabaseSeeder"
                );
            }
            if (! $user->is_active) {
                $user->update(['is_active' => true]);
                ReferralCode::query()->where('user_id', $user->id)->update(['is_active' => true]);
                $user->refresh();
            }
            $out[$key] = $user;
        }

        return $out;
    }
}
