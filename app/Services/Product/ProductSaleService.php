<?php

namespace App\Services\Product;

use App\Models\ProductSale;
use App\Services\Commission\CommissionDistributor;
use App\Services\Commission\SalePartyResolver;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ProductSaleService
{
    public function __construct(
        private readonly SalePartyResolver $parties,
        private readonly CommissionDistributor $distributor,
    ) {}

    /**
     * Create (or reuse) a successful product sale with org parties resolved from webhook owners.
     *
     * @param  array<string, mixed>  $payload
     */
    public function resolveFromWebhook(array $payload, string $productType, ?string $productCode, string $amount): ProductSale
    {
        $external = trim((string) ($payload['external_sale_id'] ?? $payload['order_id'] ?? $payload['external_ref'] ?? ''));
        $key = trim((string) ($payload['sale_idempotency_key'] ?? ''));
        if ($key === '') {
            $key = $external !== ''
                ? 'product-sale:'.$productType.':'.$external
                : 'product-sale:'.$productType.':'.Str::uuid();
        }

        if ($existing = ProductSale::query()->where('idempotency_key', $key)->first()) {
            return $existing->load(['representatives.user', 'referrers.user', 'managers.user', 'managers.role']);
        }

        $reps = $this->resolveOwners($payload);
        $this->distributor->assertShares($reps);

        $points = (int) (config("finopal.products.{$productType}.sale_points")
            ?? config('finopal.products._default.sale_points', 0));

        return DB::transaction(function () use ($payload, $productType, $productCode, $amount, $external, $key, $reps, $points) {
            $sale = ProductSale::query()->create([
                'product_type' => $productType,
                'product_code' => $productCode,
                'title' => $payload['title'] ?? (config("finopal.products.{$productType}.label") ?? $productType),
                'external_ref' => $external !== '' ? $external : null,
                'amount' => $amount,
                'full_sales_points' => $points,
                'status' => 'successful',
                'sold_at' => $payload['paid_at'] ?? now(),
                'idempotency_key' => $key,
                'metadata' => [
                    'source' => 'finopal_webhook',
                    'owners_raw' => $payload['owners'] ?? null,
                ],
            ]);

            foreach ($reps as $rep) {
                $sale->representatives()->create([
                    'user_id' => $rep['user_id'],
                    'share_percent' => $rep['share_percent'],
                    'sales_points' => Money::percentOf((string) $sale->full_sales_points, (string) $rep['share_percent']),
                ]);
            }

            foreach ($this->parties->resolveReferrers($reps) as $ref) {
                $sale->referrers()->create($ref);
            }

            foreach ($this->parties->resolveManagers($reps[0]['user_id']) as $manager) {
                $sale->managers()->create($manager);
            }

            return $sale->fresh(['representatives.user', 'referrers.user', 'managers.user', 'managers.role']);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{user_id:int, share_percent:string}>
     */
    private function resolveOwners(array $payload): array
    {
        $owners = $payload['owners'] ?? null;
        if (is_array($owners) && $owners !== []) {
            $out = [];
            foreach ($owners as $owner) {
                if (! is_array($owner)) {
                    continue;
                }
                $user = $this->parties->findUserByIdentity(
                    $owner['national_id'] ?? $owner['NationalCode'] ?? $owner['national_code'] ?? null,
                    $owner['mobile'] ?? $owner['phone'] ?? $owner['PhoneNumber'] ?? null,
                    isset($owner['user_id']) ? (int) $owner['user_id'] : null,
                );
                if (! $user) {
                    throw new RuntimeException('مالک محصول (owners) در سامانه پیدا نشد.');
                }
                $out[] = [
                    'user_id' => $user->id,
                    'share_percent' => Money::normalize((string) ($owner['share_percent'] ?? $owner['share'] ?? '0'), 3),
                ];
            }
            if ($out === []) {
                throw new RuntimeException('لیست owners معتبر نیست.');
            }

            return $out;
        }

        $user = $this->parties->findUserByIdentity(
            $payload['owner_national_id'] ?? $payload['representative_national_id'] ?? $payload['NationalCode'] ?? null,
            $payload['owner_mobile'] ?? $payload['representative_mobile'] ?? null,
            isset($payload['owner_user_id']) ? (int) $payload['owner_user_id'] : (
                isset($payload['representative_user_id']) ? (int) $payload['representative_user_id'] : null
            ),
        );

        if (! $user) {
            throw new RuntimeException('برای محصول غیر درگاه باید owner_national_id / owners مشخص باشد.');
        }

        return [[
            'user_id' => $user->id,
            'share_percent' => '100.000',
        ]];
    }
}
