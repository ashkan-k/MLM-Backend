<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Sms\SmsService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class SmsDebugController extends Controller
{
    public function index(SmsService $sms)
    {
        $logs = $sms->readRecentLogs();
        $providers = $sms->providerStatus();

        $failedToday = collect($logs)
            ->where('success', false)
            ->filter(function ($log) {
                try {
                    return Carbon::parse($log['at'] ?? null)->isToday();
                } catch (\Throwable) {
                    return false;
                }
            })
            ->count();

        return response()->json([
            'logs' => $logs,
            'providers' => $providers,
            'stats' => [
                'total' => count($logs),
                'success' => collect($logs)->where('success', true)->count(),
                'failed' => collect($logs)->where('success', false)->count(),
                'failed_today' => $failedToday,
            ],
            'log_path' => $sms->logPath(),
            'text_order' => $sms->textProvidersOrder(),
            'driver' => config('sms.driver'),
            'frontend_login_url' => config('sms.frontend_login_url'),
        ]);
    }

    public function testSend(Request $request, SmsService $sms)
    {
        $data = $request->validate([
            'phone' => ['required', 'regex:/^09\d{9}$/'],
            'provider' => ['nullable', 'string', 'max:40'],
            'message' => ['nullable', 'string', 'max:500'],
        ], [
            'phone.regex' => 'شماره موبایل باید ۱۱ رقم و با 09 شروع شود.',
        ]);

        $phone = $data['phone'];
        $text = $data['message'] ?? ('تست پیامک فاینوپال'."\n".now()->format('Y-m-d H:i:s'));
        $provider = $data['provider'] ?? null;

        $result = $provider
            ? $sms->sendTest($provider, $phone, $text)
            : $sms->sendText($phone, $text, [
                'type' => 'dashboard_test',
                'source' => 'superuser_chain_test',
                'user_id' => $request->user()?->id,
            ]);

        return response()->json([
            'success' => (bool) ($result['success'] ?? false),
            'message' => ($result['success'] ?? false)
                ? 'پیامک تست از طریق «'.($result['provider'] ?? '—').'» ارسال/ثبت شد.'
                : ('ارسال ناموفق: '.($result['reason'] ?? 'خطای نامشخص')),
            'result' => $result,
        ], ($result['success'] ?? false) ? 200 : 422);
    }

    public function clearLogs(SmsService $sms)
    {
        $sms->clearLogs();

        return response()->json(['success' => true, 'message' => 'لاگ پیامک‌ها پاک شد.']);
    }
}
