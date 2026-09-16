<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Integration\Finopal\FinopalTransactionService;
use Illuminate\Http\Request;
use RuntimeException;

class FinopalWebhookController extends Controller
{
    public function transaction(Request $request, FinopalTransactionService $transactions)
    {
        $expected = (string) config('finopal.webhook_secret');
        if ($expected === '') {
            return response()->json(['message' => 'وب‌هوک فاینوپال پیکربندی نشده است.'], 503);
        }

        $provided = (string) (
            $request->header('X-Finopal-Webhook-Secret')
            ?? $request->bearerToken()
            ?? $request->input('webhook_secret')
            ?? ''
        );
        if (! hash_equals($expected, $provided)) {
            return response()->json(['message' => 'امضای وب‌هوک نامعتبر است.'], 401);
        }

        $data = $request->validate([
            'event' => ['nullable', 'string', 'max:80'],
            'merchant_id' => ['required_without:merchant_code', 'string', 'max:64'],
            'merchant_code' => ['nullable', 'string', 'max:64'],
            'authority' => ['nullable', 'string', 'max:120'],
            'ref_id' => ['nullable', 'string', 'max:120'],
            'order_id' => ['nullable', 'string', 'max:120'],
            'amount' => ['required', 'numeric', 'min:0'],
            'profit' => ['required', 'numeric', 'min:0'],
            'gateway_profit' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:8'],
            'status' => ['nullable', 'string', 'max:40'],
            'code' => ['nullable', 'integer'],
            'paid_at' => ['nullable', 'date'],
            'idempotency_key' => ['nullable', 'string', 'max:190'],
            'payer' => ['nullable', 'array'],
            'metadata' => ['nullable', 'array'],
        ], [], [
            'merchant_id' => 'کد مرچنت (merchant_id)',
            'merchant_code' => 'کد مرچنت (merchant_code)',
            'amount' => 'مبلغ تراکنش',
            'profit' => 'سود درگاه',
            'gateway_profit' => 'سود درگاه',
            'currency' => 'واحد پول',
            'authority' => 'شناسه authority',
            'ref_id' => 'شماره پیگیری',
            'order_id' => 'شماره سفارش',
            'event' => 'رویداد',
            'status' => 'وضعیت',
            'code' => 'کد نتیجه',
            'paid_at' => 'زمان پرداخت',
            'idempotency_key' => 'کلید یکتایی',
            'payer' => 'اطلاعات پرداخت‌کننده',
            'metadata' => 'متادیتا',
        ]);

        try {
            $tx = $transactions->ingest($data + $request->all());
        } catch (RuntimeException $e) {
            $status = str_contains($e->getMessage(), 'پیدا نشد') ? 404 : 422;

            return response()->json(['message' => $e->getMessage()], $status);
        }

        $duplicate = (bool) $tx->getAttribute('was_duplicate');

        return response()->json([
            'success' => true,
            'message' => $duplicate
                ? 'این تراکنش قبلاً دریافت شده است (تکراری).'
                : 'تراکنش با موفقیت ثبت شد.',
            'id' => $tx->id,
            'duplicate' => $duplicate,
            'status' => $tx->status,
            'amount' => $tx->amount,
            'profit' => $tx->profit,
            'commissions' => $tx->commissions->count(),
        ]);
    }
}
