<?php

namespace App\Services\Sms;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * ارسال پیامک با لاگ ساختاریافته (مشابه بونگو / سه‌سکه).
 * هر درخواست یک خط JSON در storage/logs/sms.log می‌نویسد.
 */
class SmsService
{
    public function send(string $mobile, string $message): bool
    {
        return (bool) ($this->sendText($mobile, $message, ['type' => 'text'])['success'] ?? false);
    }

    public function sendWelcome(User $user, string $plainPassword, string $roleSlug): bool
    {
        $roleName = Role::query()->where('slug', $roleSlug)->value('name') ?: $roleSlug;
        $message = strtr((string) config('sms.welcome_template'), [
            '{login_url}' => (string) config('sms.frontend_login_url'),
            '{username}' => (string) $user->mobile,
            '{password}' => $plainPassword,
            '{role}' => (string) $roleName,
            '{name}' => (string) ($user->name ?? ''),
        ]);

        $result = $this->sendText((string) $user->mobile, $message, [
            'type' => 'org_sync_welcome',
            'user_id' => $user->id,
            'role' => $roleSlug,
        ]);

        return (bool) ($result['success'] ?? false);
    }

    /**
     * ارسال متن با fallback بین سامانه‌ها + ثبت لاگ.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public function sendText(string $phone, string $text, array $meta = []): array
    {
        $start = microtime(true);
        $phone = $this->normalizePhone($phone);
        $text = trim($text);

        if ($phone === '' || $text === '') {
            $result = $this->finalResult(false, [[
                'success' => false,
                'provider_key' => null,
                'provider' => null,
                'reason' => 'شماره یا متن پیامک خالی است',
                'skipped' => true,
            ]], $start);
            $this->writeLog($meta['type'] ?? 'text', $phone, $meta, $result, $text);

            return $result;
        }

        $attempts = [];
        foreach ($this->textProvidersOrder() as $key) {
            $attempt = $this->sendToProvider($key, $phone, $text);
            $attempts[] = $attempt;
            if (! empty($attempt['success'])) {
                break;
            }
        }

        if ($attempts === []) {
            $attempts[] = [
                'success' => false,
                'provider_key' => null,
                'provider' => null,
                'reason' => 'هیچ سامانه فعالی پیکربندی نشده است',
                'skipped' => true,
            ];
        }

        $result = $this->finalResult($this->chainSucceeded($attempts), $attempts, $start);
        $this->writeLog($meta['type'] ?? 'text', $phone, $meta, $result, $text);

        return $result;
    }

    /**
     * تست یک سامانه بدون fallback.
     *
     * @return array<string, mixed>
     */
    public function sendTest(string $providerKey, string $phone, string $text = ''): array
    {
        $start = microtime(true);
        $phone = $this->normalizePhone($phone);
        $providers = (array) config('sms.providers', []);

        if (! array_key_exists($providerKey, $providers)) {
            return $this->finalResult(false, [[
                'success' => false,
                'provider_key' => $providerKey,
                'provider' => $providerKey,
                'reason' => 'سامانه ناشناخته است',
                'skipped' => true,
            ]], $start);
        }

        if ($text === '') {
            $text = "تست پیامک فاینوپال\n".now()->format('Y-m-d H:i:s');
        }

        $attempt = $this->sendToProvider($providerKey, $phone, $text);
        $result = $this->finalResult(! empty($attempt['success']), [$attempt], $start);
        $this->writeLog('dashboard_test', $phone, [
            'provider_key' => $providerKey,
            'source' => 'superuser_sms_debug',
        ], $result, $text);

        return $result;
    }

    /** @return list<array<string, mixed>> */
    public function providerStatus(): array
    {
        $out = [];
        foreach ((array) config('sms.providers', []) as $key => $cfg) {
            $out[] = [
                'key' => $key,
                'label' => $cfg['label'] ?? $key,
                'enabled' => ! empty($cfg['enabled']),
                'configured' => $this->isProviderConfigured((string) $key, (array) $cfg),
            ];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    public function readRecentLogs(?int $limit = null): array
    {
        $limit = $limit ?? (int) config('sms.log_max_entries_dashboard', 200);
        $path = $this->logPath();
        if (! is_file($path)) {
            return [];
        }

        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $lines = array_slice($lines, -$limit);
        $entries = [];
        foreach (array_reverse($lines) as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $entries[] = $decoded;
            }
        }

        return $entries;
    }

    public function clearLogs(): void
    {
        @file_put_contents($this->logPath(), '');
    }

    public function logPath(): string
    {
        return (string) config('sms.log_path', storage_path('logs/sms.log'));
    }

    /** @return list<string> */
    public function textProvidersOrder(): array
    {
        $csv = trim((string) config('sms.text_providers_order', ''));
        if ($csv === '') {
            $driver = (string) config('sms.driver', 'log');
            $csv = match ($driver) {
                'http' => 'http,log',
                'null', 'none', '' => '',
                default => 'log',
            };
        }

        return $this->parseOrder($csv);
    }

    public static function typeLabel(?string $type): string
    {
        return match ($type) {
            'org_sync_welcome' => 'خوش‌آمد سینک سازمانی',
            'dashboard_test' => 'تست داشبورد',
            'text' => 'متن آزاد',
            default => $type ?: 'نامشخص',
        };
    }

    /** @return array<string, mixed> */
    private function sendToProvider(string $providerKey, string $phone, string $text): array
    {
        $cfg = (array) config('sms.providers.'.$providerKey, []);
        if ($cfg === [] || empty($cfg['enabled'])) {
            return $this->skipped($providerKey, 'سامانه غیرفعال است');
        }

        return match ($providerKey) {
            'http' => $this->sendViaHttp($phone, $text),
            'log' => $this->sendViaLog($phone, $text),
            default => $this->skipped($providerKey, 'سامانه پشتیبانی نشده'),
        };
    }

    /** @return array<string, mixed> */
    private function sendViaLog(string $phone, string $text): array
    {
        Log::info('sms.provider_log', ['mobile' => $phone, 'message' => $text]);

        return [
            'success' => true,
            'provider_key' => 'log',
            'provider' => (string) (config('sms.providers.log.label') ?? 'لاگ محلی'),
            'http_status' => null,
            'response' => 'logged-locally',
            'reason' => 'ثبت در لاگ محلی (بدون ارسال واقعی)',
        ];
    }

    /** @return array<string, mixed> */
    private function sendViaHttp(string $phone, string $text): array
    {
        $cfg = (array) config('sms.providers.http', []);
        $url = (string) ($cfg['url'] ?? '');
        $label = (string) ($cfg['label'] ?? 'HTTP');

        if ($url === '') {
            return $this->skipped('http', 'SMS_HTTP_URL تنظیم نشده');
        }

        try {
            $payload = [
                (string) ($cfg['receptor_param'] ?? 'receptor') => $phone,
                (string) ($cfg['message_param'] ?? 'message') => $text,
            ];
            $sender = (string) config('sms.from', '');
            $senderParam = (string) ($cfg['sender_param'] ?? 'sender');
            if ($sender !== '' && $senderParam !== '') {
                $payload[$senderParam] = $sender;
            }

            $request = Http::timeout((int) config('sms.timeout', 15))
                ->connectTimeout((int) config('sms.connect_timeout', 8))
                ->acceptJson()
                ->asForm();

            $token = (string) ($cfg['token'] ?? '');
            if ($token !== '') {
                $request = $request->withToken($token);
            }

            $response = $request->post($url, $payload);
            $body = $response->body();
            $ok = $response->successful();

            return [
                'success' => $ok,
                'provider_key' => 'http',
                'provider' => $label,
                'http_status' => $response->status(),
                'response' => Str::limit($body, 800),
                'reason' => $ok ? 'ارسال موفق' : ('خطای HTTP '.$response->status()),
            ];
        } catch (Throwable $e) {
            return $this->exceptionResult('http', $label, $e);
        }
    }

    /** @param  array<string, mixed>  $cfg */
    private function isProviderConfigured(string $key, array $cfg): bool
    {
        return match ($key) {
            'http' => ! empty($cfg['url']),
            'log' => true,
            default => false,
        };
    }

    /** @return list<string> */
    private function parseOrder(string $csv): array
    {
        $keys = array_values(array_filter(array_map('trim', explode(',', $csv))));
        $valid = array_keys((array) config('sms.providers', []));

        return array_values(array_intersect($keys, $valid));
    }

    /** @return array<string, mixed> */
    private function skipped(string $providerKey, string $reason): array
    {
        return [
            'success' => false,
            'provider_key' => $providerKey,
            'provider' => (string) (config("sms.providers.{$providerKey}.label") ?? $providerKey),
            'reason' => $reason,
            'response' => null,
            'http_status' => null,
            'skipped' => true,
        ];
    }

    /** @return array<string, mixed> */
    private function exceptionResult(string $providerKey, string $label, Throwable $e): array
    {
        return [
            'success' => false,
            'provider_key' => $providerKey,
            'provider' => $label,
            'reason' => $e->getMessage(),
            'response' => null,
            'http_status' => null,
            'exception' => $e::class,
        ];
    }

    /** @param  list<array<string, mixed>>  $attempts */
    private function chainSucceeded(array $attempts): bool
    {
        foreach ($attempts as $attempt) {
            if (! empty($attempt['success'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $attempts
     * @return array<string, mixed>
     */
    private function finalResult(bool $success, array $attempts, float $start): array
    {
        $used = null;
        foreach ($attempts as $attempt) {
            if (! empty($attempt['success'])) {
                $used = $attempt;
                break;
            }
        }
        $used = $used ?: ($attempts ? end($attempts) : []);

        return [
            'success' => $success,
            'provider' => $used['provider'] ?? null,
            'provider_key' => $used['provider_key'] ?? null,
            'reason' => $success
                ? ($used['reason'] ?? 'ارسال موفق')
                : ($used['reason'] ?? 'ارسال ناموفق در همهٔ سامانه‌ها'),
            'response' => $used['response'] ?? null,
            'http_status' => $used['http_status'] ?? null,
            'attempts' => $attempts,
            'duration_ms' => (int) round((microtime(true) - $start) * 1000),
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $result
     */
    private function writeLog(string $type, string $phone, array $meta, array $result, string $message = ''): void
    {
        $entry = [
            'at' => now()->toIso8601String(),
            'type' => $type,
            'type_label' => self::typeLabel($type),
            'phone' => $phone,
            'message' => $message !== '' ? Str::limit($message, 2000) : null,
            'success' => ! empty($result['success']),
            'provider' => $result['provider'] ?? null,
            'provider_key' => $result['provider_key'] ?? null,
            'reason' => $result['reason'] ?? null,
            'http_status' => $result['http_status'] ?? null,
            'response' => isset($result['response']) ? Str::limit((string) $result['response'], 500) : null,
            'duration_ms' => $result['duration_ms'] ?? null,
            'ip' => request()?->ip(),
            'meta' => array_filter($meta, fn ($v) => ! is_null($v) && $v !== ''),
            'attempts' => array_map(function (array $attempt) {
                return [
                    'provider_key' => $attempt['provider_key'] ?? null,
                    'provider' => $attempt['provider'] ?? null,
                    'success' => ! empty($attempt['success']),
                    'skipped' => ! empty($attempt['skipped']),
                    'reason' => $attempt['reason'] ?? null,
                    'http_status' => $attempt['http_status'] ?? null,
                    'exception' => $attempt['exception'] ?? null,
                    'response' => isset($attempt['response'])
                        ? Str::limit((string) $attempt['response'], 500)
                        : null,
                ];
            }, $result['attempts'] ?? []),
        ];

        $this->appendJsonLine($entry);
    }

    /** @param  array<string, mixed>  $entry */
    private function appendJsonLine(array $entry): void
    {
        $path = $this->logPath();
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL;
        @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);

        $max = (int) config('sms.log_max_bytes', 2 * 1024 * 1024);
        if (is_file($path) && filesize($path) > $max) {
            $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            $keep = array_slice($lines, -1500);
            @file_put_contents($path, implode(PHP_EOL, $keep).PHP_EOL, LOCK_EX);
        }
    }

    private function normalizePhone(string $phone): string
    {
        $map = [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ];
        $digits = preg_replace('/\D+/', '', strtr(trim($phone), $map)) ?? '';
        if (str_starts_with($digits, '98') && strlen($digits) === 12) {
            $digits = '0'.substr($digits, 2);
        }
        if (strlen($digits) === 10 && str_starts_with($digits, '9')) {
            $digits = '0'.$digits;
        }

        return preg_match('/^09\d{9}$/', $digits) ? $digits : trim($phone);
    }
}
