<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Integration\Finopal\FinopalOrgSyncService;
use App\Services\Integration\Finopal\FinopalSignupWebhookService;
use App\Services\Integration\Finopal\FinopalTransactionService;
use App\Services\Integration\Finopal\FinopalWebhookAuditor;
use App\Support\ProductCatalog;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class FinopalWebhookController extends Controller
{
    public function transaction(Request $request, FinopalTransactionService $transactions, FinopalWebhookAuditor $auditor)
    {
        if ($response = $this->authorizeWebhook($request, $auditor, 'transaction')) {
            return $response;
        }

        $data = $request->validate([
            'event' => ['nullable', 'string', 'max:80'],
            'product_type' => ['nullable', 'string', 'max:64'],
            'product_code' => ['nullable', 'string', 'max:120'],
            'title' => ['nullable', 'string', 'max:190'],
            'merchant_id' => ['nullable', 'string', 'max:64'],
            'merchant_code' => ['nullable', 'string', 'max:64'],
            'owner_national_id' => ['nullable', 'string', 'max:20'],
            'owner_mobile' => ['nullable', 'string', 'max:20'],
            'owner_user_id' => ['nullable', 'integer', 'min:1'],
            'representative_national_id' => ['nullable', 'string', 'max:20'],
            'representative_user_id' => ['nullable', 'integer', 'min:1'],
            'owners' => ['nullable', 'array', 'min:1'],
            'owners.*.national_id' => ['nullable', 'string', 'max:20'],
            'owners.*.mobile' => ['nullable', 'string', 'max:20'],
            'owners.*.user_id' => ['nullable', 'integer', 'min:1'],
            'owners.*.share_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'external_sale_id' => ['nullable', 'string', 'max:190'],
            'authority' => ['nullable', 'string', 'max:120'],
            'ref_id' => ['nullable', 'string', 'max:120'],
            'order_id' => ['nullable', 'string', 'max:120'],
            'amount' => ['required', 'numeric', 'min:0'],
            'profit' => ['required', 'numeric', 'min:0'],
            'gateway_profit' => ['nullable', 'numeric', 'min:0'],
            'commission_base' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:8'],
            'status' => ['nullable', 'string', 'max:40'],
            'code' => ['nullable', 'integer'],
            'paid_at' => ['nullable', 'date'],
            'idempotency_key' => ['nullable', 'string', 'max:190'],
            'payer' => ['nullable', 'array'],
            'metadata' => ['nullable', 'array'],
        ], [], [
            'product_type' => 'نوع محصول',
            'product_code' => 'کد محصول',
            'merchant_id' => 'کد مرچنت (merchant_id)',
            'merchant_code' => 'کد مرچنت (merchant_code)',
            'owner_national_id' => 'کد ملی مالک',
            'owners' => 'مالکان محصول',
            'amount' => 'مبلغ تراکنش',
            'profit' => 'پایه پورسانت (سود)',
            'gateway_profit' => 'سود درگاه',
            'commission_base' => 'پایه پورسانت',
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

        $productType = ProductCatalog::normalize($data['product_type'] ?? null);
        if (! ProductCatalog::known($productType)) {
            return response()->json(['message' => 'این نوع محصول در سامانه تعریف نشده است.'], 422);
        }
        $isGateway = (bool) (ProductCatalog::definition($productType)['requires_merchant'] ?? false);
        if ($isGateway && empty($data['merchant_id']) && empty($data['merchant_code'])) {
            return response()->json(['message' => 'برای محصول درگاه، merchant_id الزامی است.'], 422);
        }
        if (! $isGateway
            && empty($data['owner_national_id'])
            && empty($data['representative_national_id'])
            && empty($data['owner_user_id'])
            && empty($data['representative_user_id'])
            && empty($data['owners'])
        ) {
            return response()->json(['message' => 'برای محصول غیر درگاه، owner_national_id یا owners الزامی است.'], 422);
        }

        try {
            $tx = $transactions->ingest($data + $request->all());
        } catch (RuntimeException $e) {
            $status = str_contains($e->getMessage(), 'پیدا نشد') ? 404 : 422;

            return $this->audited($auditor, 'transaction', $request, response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $status));
        }

        $duplicate = (bool) $tx->getAttribute('was_duplicate');

        return $this->audited($auditor, 'transaction', $request, response()->json([
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
        ]));
    }

    /**
     * Sync Finopal organization members (Senior / DM / SM / REPS) into local MLM tree.
     */
    public function orgStructure(Request $request, FinopalOrgSyncService $sync, FinopalWebhookAuditor $auditor)
    {
        if ($response = $this->authorizeWebhook($request, $auditor, 'org-structure')) {
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
            // Validated data only keeps listed keys. Nested birth dates and profile
            // fields must still reach the sync, so the original body fills the gaps.
            $result = $sync->sync(array_replace_recursive($data, $request->all()));
        } catch (RuntimeException $e) {
            return $this->audited($auditor, 'org-structure', $request, response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422));
        }

        $createdCount = count($result['created']);
        $skippedCount = count($result['skipped']);
        $errorCount = count($result['errors']);
        $alreadyRegistered = collect($result['errors'])
            ->contains(fn ($row) => ($row['code'] ?? null) === 'already_registered');
        $success = $errorCount === 0;

        return $this->audited($auditor, 'org-structure', $request, response()->json([
            'success' => $success,
            'message' => $success
                ? 'همگام‌سازی ساختار سازمانی انجام شد.'
                : ($alreadyRegistered && $createdCount === 0
                    ? 'یک یا چند کاربر قبلاً ثبت شده‌اند و دوباره ثبت نشدند.'
                    : 'همگام‌سازی با برخی خطاها انجام شد.'),
            'created_count' => $createdCount,
            'skipped_count' => $skippedCount,
            'error_count' => $errorCount,
            'welcome_sms_queued' => (int) ($result['sms_queued'] ?? 0),
            'created' => $result['created'],
            'skipped' => $result['skipped'],
            'errors' => $result['errors'],
            'structure' => $result['structure'],
        ], (! $success && $createdCount === 0) ? 422 : 200));
    }

    /**
     * Step 1. A person signed up on Finopal with a referrer link.
     * The gateway may be completed days later and arrives on gateway().
     */
    public function signup(Request $request, FinopalSignupWebhookService $signups, FinopalWebhookAuditor $auditor)
    {
        if ($response = $this->authorizeWebhook($request, $auditor, 'signup')) {
            return $response;
        }

        try {
            $data = $request->validate([
                'event' => ['nullable', 'string', 'max:80'],
                'idempotency_key' => ['nullable', 'string', 'max:190'],
                'referral_code' => ['required', 'string', 'max:255'],
                'user' => ['required', 'array'],
                'user.name' => ['required', 'string', 'max:255'],
                'user.mobile' => ['required', 'string', 'max:20'],
                'user.national_id' => ['required', 'string', 'max:20'],
                'user.email' => ['nullable', 'email', 'max:190'],
                'user.birth_date' => ['nullable', 'string', 'max:20'],
                'user.sheba' => ['nullable', 'string', 'max:34'],
                'user.finopal_user_id' => ['nullable', 'string', 'max:64'],
            ], [
                'referral_code.required' => 'کد معرف (ref) الزامی است.',
                'user.required' => 'اطلاعات کاربر الزامی است.',
                'user.name.required' => 'نام کاربر الزامی است.',
                'user.mobile.required' => 'موبایل کاربر الزامی است.',
                'user.national_id.required' => 'کد ملی کاربر الزامی است.',
            ], [
                'referral_code' => 'کد معرف',
                'user.name' => 'نام',
                'user.mobile' => 'موبایل',
                'user.national_id' => 'کد ملی',
                'user.email' => 'ایمیل',
            ]);

            $result = $signups->registerUser($data);

            return $this->audited($auditor, 'signup', $request, response()->json([
                'success' => true,
                'message' => 'ثبت کاربر از فینوپال در سازمان فروش انجام شد.',
                ...$result,
            ]), $data);
        } catch (ValidationException $e) {
            $errors = $e->errors();
            $first = collect($errors)->flatten()->first();

            return $this->audited($auditor, 'signup', $request, response()->json([
                'success' => false,
                'message' => $first ?: 'اطلاعات ارسالی نامعتبر است.',
                'errors' => $errors,
            ], $e->status));
        } catch (Throwable $e) {
            $auditor->failure('finopal.webhook.signup.error', [
                'exception' => class_basename($e),
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Step 2. The same person finished gateway registration on Finopal.
     */
    public function gateway(Request $request, FinopalSignupWebhookService $signups, FinopalWebhookAuditor $auditor)
    {
        if ($response = $this->authorizeWebhook($request, $auditor, 'gateway')) {
            return $response;
        }

        try {
            $data = $request->validate([
                'event' => ['nullable', 'string', 'max:80'],
                'idempotency_key' => ['nullable', 'string', 'max:190'],
                'user' => ['required', 'array'],
                'user.name' => ['nullable', 'string', 'max:255'],
                'user.mobile' => ['required', 'string', 'max:20'],
                'user.national_id' => ['required', 'string', 'max:20'],
                'user.email' => ['nullable', 'email', 'max:190'],
                'user.birth_date' => ['nullable', 'string', 'max:20'],
                'user.sheba' => ['nullable', 'string', 'max:34'],
                'user.finopal_user_id' => ['nullable', 'string', 'max:64'],
                'gateway' => ['required', 'array'],
                'gateway.external_id' => ['nullable', 'string', 'max:190'],
                'gateway.merchant_code' => ['nullable', 'string', 'max:64'],
                'gateway.name' => ['nullable', 'string', 'max:190'],
                'gateway.amount' => ['nullable', 'numeric', 'min:0'],
                'gateway.website' => ['nullable', 'string', 'max:190'],
                'gateway.callback_url' => ['nullable', 'string', 'max:190'],
                'gateway.person_type' => ['nullable', 'string', 'max:32'],
                'gateway.sold_at' => ['nullable', 'date'],
            ], [
                'user.required' => 'اطلاعات کاربر الزامی است.',
                'user.mobile.required' => 'موبایل کاربر الزامی است.',
                'user.national_id.required' => 'کد ملی کاربر الزامی است.',
                'gateway.required' => 'اطلاعات درگاه الزامی است.',
            ], [
                'user.name' => 'نام',
                'user.mobile' => 'موبایل',
                'user.national_id' => 'کد ملی',
                'gateway.external_id' => 'شناسه درگاه',
                'gateway.merchant_code' => 'کد مرچنت',
                'gateway.name' => 'نام درگاه',
                'gateway.amount' => 'مبلغ',
            ]);

            $result = $signups->registerGateway($data);

            return $this->audited($auditor, 'gateway', $request, response()->json([
                'success' => true,
                'message' => $result['gateway_created']
                    ? 'ثبت درگاه از فینوپال در سازمان فروش انجام شد.'
                    : 'این درگاه از قبل ثبت شده بود و دوباره ساخته نشد.',
                ...$result,
            ]), $data);
        } catch (ValidationException $e) {
            $errors = $e->errors();
            $first = collect($errors)->flatten()->first();

            return $this->audited($auditor, 'gateway', $request, response()->json([
                'success' => false,
                'message' => $first ?: 'اطلاعات ارسالی نامعتبر است.',
                'errors' => $errors,
            ], $e->status));
        } catch (Throwable $e) {
            $auditor->failure('finopal.webhook.gateway.error', [
                'exception' => class_basename($e),
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function authorizeWebhook(Request $request, ?FinopalWebhookAuditor $auditor = null, string $endpoint = 'unknown')
    {
        $expected = (string) config('finopal.webhook_secret');
        if ($expected === '') {
            $response = response()->json(['success' => false, 'message' => 'وب‌هوک فاینوپال پیکربندی نشده است.'], 503);
            $auditor?->fromResponse($endpoint, $request, $response);

            return $response;
        }

        $provided = (string) (
            $request->header('X-Finopal-Webhook-Secret')
            ?? $request->bearerToken()
            ?? $request->input('webhook_secret')
            ?? ''
        );
        if (! hash_equals($expected, $provided)) {
            $response = response()->json(['success' => false, 'message' => 'امضای وب‌هوک نامعتبر است.'], 401);
            $auditor?->fromResponse($endpoint, $request, $response);

            return $response;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function audited(FinopalWebhookAuditor $auditor, string $endpoint, Request $request, Response $response, ?array $payload = null): Response
    {
        $auditor->fromResponse($endpoint, $request, $response, $payload);

        return $response;
    }
}
