<?php

namespace App\Services\Integration\Finopal;

use App\Models\FinopalTransaction;
use App\Models\Gateway;
use App\Models\GatewaySale;
use App\Models\Notification;
use App\Models\ProductSale;
use App\Services\Commission\CommissionEngine;
use App\Services\Product\ProductSaleService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FinopalTransactionService
{
    public function __construct(
        private readonly CommissionEngine $engine,
        private readonly ProductSaleService $productSales,
    ) {}

    public function ingest(array $payload): FinopalTransaction
    {
        unset($payload['webhook_secret']);

        $productType = $this->normalizeProductType($payload['product_type'] ?? $payload['productType'] ?? null);
        $productCode = isset($payload['product_code'])
            ? trim((string) $payload['product_code'])
            : (isset($payload['productCode']) ? trim((string) $payload['productCode']) : null);
        if ($productCode === '') {
            $productCode = null;
        }

        $definition = $this->productDefinition($productType);
        $requiresMerchant = (bool) ($definition['requires_merchant'] ?? ($productType === 'gateway_profit'));

        $merchant = trim((string) ($payload['merchant_id'] ?? $payload['merchant_code'] ?? ''));
        if ($requiresMerchant && $merchant === '') {
            throw new RuntimeException('برای محصول درگاه، merchant_id الزامی است.');
        }

        $key = $this->idempotencyKey($payload, $merchant !== '' ? $merchant : $productType);
        if ($existing = FinopalTransaction::query()->where('idempotency_key', $key)->first()) {
            $existing->setAttribute('was_duplicate', true);

            return $existing->load(['gateway', 'sale', 'productSale', 'commissions.role']);
        }

        $event = (string) ($payload['event'] ?? 'transaction.verified');
        $status = strtolower((string) ($payload['status'] ?? 'verified'));
        $code = isset($payload['code']) ? (int) $payload['code'] : null;
        $verified = $this->isVerified($event, $status, $code);
        [$amount, $profit, $currency] = $this->amounts($payload);

        return DB::transaction(function () use (
            $payload,
            $merchant,
            $key,
            $event,
            $status,
            $code,
            $verified,
            $amount,
            $profit,
            $currency,
            $productType,
            $productCode,
            $requiresMerchant,
            $definition,
        ) {
            $gateway = null;
            $gatewaySale = null;
            $productSale = null;

            if ($requiresMerchant) {
                $gateway = Gateway::query()->where('merchant_code', $merchant)->first();
                if (! $gateway) {
                    throw new RuntimeException('درگاهی با این کد مرچنت پیدا نشد.');
                }
                if (! $gateway->is_active) {
                    throw new RuntimeException('این درگاه غیرفعال است.');
                }

                $gatewaySale = $gateway->sales()
                    ->where('status', 'successful')
                    ->latest('sold_at')
                    ->first();
                if (! $gatewaySale) {
                    throw new RuntimeException('این مرچنت هنوز به یک درگاه تاییدشده وصل نیست.');
                }
            } else {
                $productSale = $this->productSales->resolveFromWebhook($payload, $productType, $productCode, $amount);
            }

            $tx = FinopalTransaction::query()->create([
                'product_type' => $productType,
                'product_code' => $productCode ?? ($definition['default_code'] ?? null),
                'gateway_id' => $gateway?->id,
                'gateway_sale_id' => $gatewaySale?->id,
                'product_sale_id' => $productSale?->id,
                'merchant_code' => $merchant !== '' ? $merchant : null,
                'event' => $event,
                'authority' => $payload['authority'] ?? null,
                'ref_id' => $payload['ref_id'] ?? null,
                'order_id' => $payload['order_id'] ?? null,
                'amount' => $amount,
                'profit' => $profit,
                'currency' => $currency,
                'status' => $verified ? 'verified' : $status,
                'code' => $code,
                'paid_at' => $payload['paid_at'] ?? now(),
                'idempotency_key' => $key,
                'payload' => $payload,
            ]);

            if ($verified && Money::cmp($profit, '0') > 0) {
                if ($gatewaySale) {
                    $this->engine->process(
                        $gatewaySale->fresh(['representatives.user', 'referrers.user', 'managers.user', 'managers.role']),
                        $tx
                    );
                    $this->notifyTree($gatewaySale, $gateway, $tx, $productType);
                } elseif ($productSale) {
                    $this->engine->processProduct(
                        $productSale->fresh(['representatives.user', 'referrers.user', 'managers.user', 'managers.role']),
                        $tx
                    );
                    $this->notifyProduct($productSale, $tx, $productType);
                }
                $tx->processed_at = now();
                $tx->save();
            }

            $tx = $tx->fresh(['gateway', 'sale', 'productSale', 'commissions.role']);
            $tx->setAttribute('was_duplicate', false);

            return $tx;
        });
    }

    private function normalizeProductType(mixed $raw): string
    {
        $type = strtolower(trim((string) ($raw ?: 'gateway_profit')));
        $type = str_replace([' ', '-'], '_', $type);

        return match ($type) {
            'gateway', 'gateway_payment', 'payment_gateway', '' => 'gateway_profit',
            'ticket', 'tickets', 'finopal_ticketing' => 'ticketing',
            default => $type,
        };
    }

    /** @return array<string, mixed> */
    private function productDefinition(string $productType): array
    {
        $known = (array) config("finopal.products.{$productType}");
        if ($known !== []) {
            return $known;
        }

        return (array) config('finopal.products._default', [
            'label' => $productType,
            'requires_merchant' => false,
            'requires_owner' => true,
            'sale_points' => 0,
        ]);
    }

    private function isVerified(string $event, string $status, ?int $code): bool
    {
        if (in_array($status, ['failed', 'nok', 'canceled', 'cancelled', 'rejected'], true)) {
            return false;
        }
        if (str_contains(strtolower($event), 'fail') || str_contains(strtolower($event), 'cancel')) {
            return false;
        }
        if ($code !== null && ! in_array($code, [100, 101], true)) {
            return false;
        }

        return true;
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function amounts(array $payload): array
    {
        $currency = strtoupper((string) ($payload['currency'] ?? 'IRR'));
        $amount = Money::normalize((string) ($payload['amount'] ?? '0'), 3);
        $profit = $payload['profit'] ?? $payload['gateway_profit'] ?? $payload['commission_base'] ?? $payload['fee'] ?? null;
        if ($profit === null) {
            throw new RuntimeException('فیلد profit (پایه تقسیم پورسانت این تراکنش) الزامی است.');
        }
        $profit = Money::normalize((string) $profit, 3);

        if (in_array($currency, ['IRR', 'RLS', 'RIAL'], true)) {
            $amount = Money::normalize(bcdiv($amount, '10', 4), 3);
            $profit = Money::normalize(bcdiv($profit, '10', 4), 3);
            $currency = 'IRT';
        }

        return [$amount, $profit, $currency];
    }

    private function idempotencyKey(array $payload, string $scope): string
    {
        if (! empty($payload['idempotency_key'])) {
            return (string) $payload['idempotency_key'];
        }
        if (! empty($payload['authority'])) {
            return 'finopal-'.$scope.'-'.$payload['authority'];
        }
        if (! empty($payload['ref_id'])) {
            return 'finopal-'.$scope.'-ref-'.$payload['ref_id'];
        }

        return 'finopal-'.$scope.'-'.sha1(json_encode($payload));
    }

    private function notifyTree(GatewaySale $sale, Gateway $gateway, FinopalTransaction $tx, string $productType): void
    {
        $label = (string) (config("finopal.products.{$productType}.label") ?? $gateway->name);
        $this->notifyCommissions(
            $tx,
            $sale->id,
            null,
            $label,
            "از فروش موفق درگاه «{$gateway->name}»",
            'gateway.transaction'
        );
    }

    private function notifyProduct(ProductSale $sale, FinopalTransaction $tx, string $productType): void
    {
        $label = (string) ($sale->title ?: (config("finopal.products.{$productType}.label") ?? $productType));
        $this->notifyCommissions(
            $tx,
            null,
            $sale->id,
            $label,
            "از فروش موفق محصول «{$label}»",
            'product.transaction'
        );
    }

    private function notifyCommissions(
        FinopalTransaction $tx,
        ?int $gatewaySaleId,
        ?int $productSaleId,
        string $label,
        string $prefix,
        string $type,
    ): void {
        $tx->loadMissing('commissions');
        $byUser = $tx->commissions
            ->groupBy('user_id')
            ->map(fn ($rows) => $rows->reduce(
                fn (string $sum, $row) => Money::add($sum, (string) $row->commission_amount),
                '0.000'
            ));

        foreach ($byUser as $userId => $amount) {
            if (Money::cmp($amount, '0') <= 0) {
                continue;
            }

            $pretty = $this->formatToman($amount);

            Notification::query()->create([
                'user_id' => $userId,
                'type' => $type,
                'title' => 'تبریک! پورسانت شما واریز شد',
                'body' => "{$prefix}، مبلغ {$pretty} تومان سود سهم شما به کیف پول نقش‌تان واریز شد. دمتون گرم — همین‌طور ادامه بدید!",
                'data' => [
                    'gateway_sale_id' => $gatewaySaleId,
                    'product_sale_id' => $productSaleId,
                    'product_type' => $tx->product_type,
                    'product_label' => $label,
                    'finopal_transaction_id' => $tx->id,
                    'commission_amount' => Money::normalize($amount),
                    'path' => 'commissions',
                ],
            ]);
        }
    }

    private function formatToman(string $amount): string
    {
        $whole = (string) (int) round((float) Money::normalize($amount, 3));

        return number_format((int) $whole, 0, '.', ',');
    }
}
