<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Integration\Finopal\FinopalOrgSyncService;
use App\Services\Integration\Finopal\FinopalTransactionService;
use Illuminate\Http\Request;
use RuntimeException;

class FinopalWebhookController extends Controller
{
    public function transaction(Request $request, FinopalTransactionService $transactions)
    {
        if ($response = $this->authorizeWebhook($request)) {
            return $response;
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

    /**
     * Sync Finopal organization members (Senior / DM / SM / REPS) into local MLM tree.
     */
    public function orgStructure(Request $request, FinopalOrgSyncService $sync)
    {
        if ($response = $this->authorizeWebhook($request)) {
            return $response;
        }

        $data = $request->validate([
            'event' => ['nullable', 'string', 'max:80'],
            'idempotency_key' => ['nullable', 'string', 'max:190'],
            'MLM_Structure' => ['required_without:mlm_structure', 'array'],
            'mlm_structure' => ['nullable', 'array'],
            'MLM_Structure.Senior' => ['nullable', 'array'],
            'MLM_Structure.DM' => ['nullable', 'array'],
            'MLM_Structure.SM' => ['nullable', 'array'],
            'MLM_Structure.REPS' => ['nullable'],
            'MLM_Structure.Senior.NationalCode' => ['nullable', 'string', 'max:20'],
            'MLM_Structure.Senior.PhoneNumber' => ['nullable', 'string', 'max:20'],
            'MLM_Structure.DM.NationalCode' => ['nullable', 'string', 'max:20'],
            'MLM_Structure.DM.PhoneNumber' => ['nullable', 'string', 'max:20'],
            'MLM_Structure.SM.NationalCode' => ['nullable', 'string', 'max:20'],
            'MLM_Structure.SM.PhoneNumber' => ['nullable', 'string', 'max:20'],
        ], [], [
            'MLM_Structure' => 'ساختار سازمانی فاینوپال',
            'event' => 'رویداد',
            'idempotency_key' => 'کلید یکتایی',
        ]);

        try {
            $result = $sync->sync($data + $request->all());
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $createdCount = count($result['created']);
        $skippedCount = count($result['skipped']);
        $errorCount = count($result['errors']);

        return response()->json([
            'success' => $errorCount === 0,
            'message' => $errorCount === 0
                ? 'همگام‌سازی ساختار سازمانی انجام شد.'
                : 'همگام‌سازی با برخی خطاها انجام شد.',
            'created_count' => $createdCount,
            'skipped_count' => $skippedCount,
            'error_count' => $errorCount,
            'welcome_sms_queued' => (int) ($result['sms_queued'] ?? 0),
            'created' => $result['created'],
            'skipped' => $result['skipped'],
            'errors' => $result['errors'],
            'structure' => $result['structure'],
        ], $errorCount > 0 && $createdCount === 0 && $skippedCount === 0 ? 422 : 200);
    }

    private function authorizeWebhook(Request $request)
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

        return null;
    }
}
