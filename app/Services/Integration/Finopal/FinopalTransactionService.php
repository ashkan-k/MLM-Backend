<?php

namespace App\Services\Integration\Finopal;

use App\Models\FinopalTransaction;
use App\Models\Gateway;
use App\Models\Notification;
use App\Services\Commission\CommissionEngine;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FinopalTransactionService
{
    public function __construct(private readonly CommissionEngine $engine) {}

    public function ingest(array $payload): FinopalTransaction
    {
        $merchant = trim((string) ($payload['merchant_id'] ?? $payload['merchant_code'] ?? ''));
        unset($payload['webhook_secret']);
        if ($merchant === '') {
            throw new RuntimeException('merchant_id الزامی است.');
        }

        $key = $this->idempotencyKey($payload, $merchant);
        if ($existing = FinopalTransaction::query()->where('idempotency_key', $key)->first()) {
            $existing->setAttribute('was_duplicate', true);

            return $existing->load(['gateway', 'sale', 'commissions.role']);
        }

        $gateway = Gateway::query()->where('merchant_code', $merchant)->first();
        if (! $gateway) {
            throw new RuntimeException('درگاهی با این کد مرچنت پیدا نشد.');
        }
        if (! $gateway->is_active) {
            throw new RuntimeException('این درگاه غیرفعال است.');
        }

        $sale = $gateway->sales()
            ->where('status', 'successful')
            ->latest('sold_at')
            ->first();
        if (! $sale) {
            throw new RuntimeException('این مرچنت هنوز به یک درگاه تاییدشده وصل نیست.');
        }

        $event = (string) ($payload['event'] ?? 'transaction.verified');
        $status = strtolower((string) ($payload['status'] ?? 'verified'));
        $code = isset($payload['code']) ? (int) $payload['code'] : null;
        $verified = $this->isVerified($event, $status, $code);

        [$amount, $profit, $currency] = $this->amounts($payload);

        return DB::transaction(function () use ($payload, $merchant, $key, $gateway, $sale, $event, $status, $code, $verified, $amount, $profit, $currency) {
            $tx = FinopalTransaction::query()->create([
                'gateway_id' => $gateway->id,
                'gateway_sale_id' => $sale->id,
                'merchant_code' => $merchant,
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
                $this->engine->process($sale->fresh(['representatives.user', 'referrers.user', 'managers.user', 'managers.role']), $tx);
                $tx->processed_at = now();
                $tx->save();
                $this->notifyTree($sale, $gateway, $tx);
            }

            $tx = $tx->fresh(['gateway', 'sale', 'commissions.role']);
            $tx->setAttribute('was_duplicate', false);

            return $tx;
        });
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
        $profit = $payload['profit'] ?? $payload['gateway_profit'] ?? $payload['fee'] ?? null;
        if ($profit === null) {
            throw new RuntimeException('فیلد profit (سود درگاه در این تراکنش) الزامی است.');
        }
        $profit = Money::normalize((string) $profit, 3);

        if (in_array($currency, ['IRR', 'RLS', 'RIAL'], true)) {
            $amount = Money::normalize(bcdiv($amount, '10', 4), 3);
            $profit = Money::normalize(bcdiv($profit, '10', 4), 3);
            $currency = 'IRT';
        }

        return [$amount, $profit, $currency];
    }

    private function idempotencyKey(array $payload, string $merchant): string
    {
        if (! empty($payload['idempotency_key'])) {
            return (string) $payload['idempotency_key'];
        }
        if (! empty($payload['authority'])) {
            return 'finopal-'.$merchant.'-'.$payload['authority'];
        }
        if (! empty($payload['ref_id'])) {
            return 'finopal-'.$merchant.'-ref-'.$payload['ref_id'];
        }

        return 'finopal-'.$merchant.'-'.sha1(json_encode($payload));
    }

    private function notifyTree($sale, Gateway $gateway, FinopalTransaction $tx): void
    {
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
                'type' => 'gateway.transaction',
                'title' => 'تبریک! پورسانت شما واریز شد',
                'body' => "از فروش موفق درگاه «{$gateway->name}»، مبلغ {$pretty} تومان سود سهم شما به کیف پول نقش‌تان واریز شد. دمتون گرم — همین‌طور ادامه بدید!",
                'data' => [
                    'gateway_sale_id' => $sale->id,
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
