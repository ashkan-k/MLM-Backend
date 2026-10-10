<?php

namespace App\Services\Integration\Finopal;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class VipPartnerClient
{
    public function enabled(): bool
    {
        return (bool) config('finopal.vip.enabled')
            && filled(config('finopal.vip.key'))
            && filled(config('finopal.vip.secret'));
    }

    public function inquirePostal(string $postalCode): array
    {
        $url = (string) config('finopal.postal.url');
        $token = (string) config('finopal.postal.token');
        if ($url === '' || $token === '') {
            throw new VipPartnerException('سرویس استعلام کد پستی پیکربندی نشده است.');
        }

        try {
            $response = Http::timeout((int) config('finopal.vip.timeout', 60))
                ->acceptJson()
                ->withToken($token)
                ->asJson()
                ->post($url, ['postalCode' => $postalCode]);
        } catch (ConnectionException) {
            Log::warning('finopal.postal.connection');
            throw new VipPartnerException('اتصال به سرویس استعلام کد پستی برقرار نشد.');
        }

        $json = $response->json();
        if (! is_array($json)) {
            $json = [];
        }
        $data = is_array($json['data'] ?? null) ? $json['data'] : [];
        $ok = $response->successful() && $this->postalSucceeded($json, $data);
        Log::info('finopal.postal.response', [
            'status' => $response->status(),
            'ok' => $ok,
            'slug' => $json['error_slug'] ?? null,
        ]);
        if (! $ok) {
            $message = trim((string) ($json['message'] ?? ''));
            throw new VipPartnerException($message !== '' ? $message : 'استعلام این کد پستی نتیجه‌ای برنگرداند.');
        }

        return $json;
    }

    public function get(string $path, array $query = []): array
    {
        return $this->send('GET', $path, function (PendingRequest $http) use ($path, $query) {
            return $http->get($this->url($path), $query);
        });
    }

    public function postJson(string $path, array $payload): array
    {
        return $this->send('POST', $path, function (PendingRequest $http) use ($path, $payload) {
            return $http->asJson()->post($this->url($path), $payload);
        });
    }

    /**
     * @param  array<string, scalar|null>  $fields
     * @param  array<string, array{path: string, name: string}>  $files
     */
    public function postForm(string $path, array $fields, array $files = []): array
    {
        return $this->send('POST', $path, function (PendingRequest $http) use ($path, $fields, $files) {
            foreach ($files as $name => $file) {
                $http = $http->attach($name, fopen($file['path'], 'r'), $file['name']);
            }

            return $http->post($this->url($path), $fields);
        });
    }

    private function send(string $method, string $path, callable $call): array
    {
        try {
            /** @var Response $response */
            $response = $call($this->pending());
        } catch (ConnectionException $e) {
            Log::warning('finopal.vip.connection', ['method' => $method, 'path' => $path]);
            throw new VipPartnerException('اتصال به فینوپال برقرار نشد. کمی بعد دوباره تلاش کنید.');
        }

        $json = $response->json();
        if (! is_array($json)) {
            $json = [];
        }

        $failed = $response->failed() || (array_key_exists('success', $json) && $json['success'] === false);
        Log::info('finopal.vip.response', [
            'method' => $method,
            'path' => $path,
            'status' => $response->status(),
            'ok' => ! $failed,
        ]);

        if ($failed) {
            Log::info('finopal.vip.rejected', [
                'method' => $method,
                'path' => $path,
                'status' => $response->status(),
                'keys' => array_keys($json),
            ]);
            throw new VipPartnerException($this->failureMessage($path, $response->status(), $json));
        }

        return $json;
    }

    private function pending(): PendingRequest
    {
        return Http::timeout((int) config('finopal.vip.timeout', 60))
            ->acceptJson()
            ->withHeaders([
                'X-Partner-Key' => (string) config('finopal.vip.key'),
                'X-Partner-Secret' => (string) config('finopal.vip.secret'),
                'X-Request-Id' => (string) Str::uuid(),
            ]);
    }

    private function postalSucceeded(array $json, array $data): bool
    {
        if ($data === []) {
            return false;
        }
        if (($json['error_slug'] ?? null) === 'success' || (int) ($json['code'] ?? -1) === 0) {
            return true;
        }

        return filled($data['address'] ?? null) || filled($data['province'] ?? null);
    }

    private function url(string $path): string
    {
        return rtrim((string) config('finopal.vip.base_url'), '/').'/api/vip/v1/'.ltrim($path, '/');
    }

    private function failureMessage(string $path, int $status, array $json): string
    {
        unset($path);
        $base = is_string($json['message'] ?? null) ? trim($json['message']) : '';
        if ($base === '' && is_string($json['error'] ?? null)) {
            $base = trim($json['error']);
        }
        $lines = $this->collectErrorLines($json);
        $generic = $base === '' || $this->genericMessage($base) || $this->technicalMessage($base);

        if ($lines !== []) {
            $shown = $generic ? [] : [$base];
            foreach ($lines as $line) {
                $already = false;
                foreach ($shown as $have) {
                    if ($have === $line || str_contains($have, $line) || str_contains($line, $have)) {
                        $already = true;
                        break;
                    }
                }
                if (! $already) {
                    $shown[] = $line;
                }
            }

            return implode('؛ ', array_slice($shown, 0, 6));
        }

        if (! $generic) {
            return $base;
        }

        if ($status === 404) {
            return 'این سرویس در فینوپال پیدا نشد.';
        }

        return 'فینوپال این درخواست را نپذیرفت. اطلاعات را بررسی کنید و دوباره تلاش کنید.';
    }

    private function collectErrorLines(array $json): array
    {
        $bags = [];
        foreach (['errors', 'error'] as $key) {
            if (isset($json[$key]) && is_array($json[$key])) {
                $bags[] = $json[$key];
            }
        }
        $nested = data_get($json, 'data.errors');
        if (is_array($nested)) {
            $bags[] = $nested;
        }

        $lines = [];
        foreach ($bags as $bag) {
            if (array_is_list($bag)) {
                foreach ($bag as $item) {
                    $text = $this->stringifyError($item);
                    if ($text !== '' && ! $this->technicalMessage($text)) {
                        $lines[] = $text;
                    }
                }
                continue;
            }
            foreach ($bag as $key => $messages) {
                $text = $this->stringifyError($messages);
                if ($text === '' || $this->technicalMessage($text)) {
                    continue;
                }
                $label = is_string($key) ? $this->fieldLabel($key) : '';
                $lines[] = ($label !== '' && ! str_contains($text, $label)) ? $label.': '.$text : $text;
            }
        }

        return array_values(array_unique($lines));
    }

    private function stringifyError(mixed $messages): string
    {
        if (is_string($messages) || is_numeric($messages)) {
            return trim((string) $messages);
        }
        if (! is_array($messages)) {
            return '';
        }
        if (isset($messages['message']) && is_string($messages['message'])) {
            return trim($messages['message']);
        }
        $parts = [];
        foreach ($messages as $item) {
            if (is_string($item) || is_numeric($item)) {
                $parts[] = trim((string) $item);
            } elseif (is_array($item) && isset($item['message']) && is_string($item['message'])) {
                $parts[] = trim($item['message']);
            }
        }

        return trim(implode(' ', array_filter($parts)));
    }

    private function genericMessage(string $text): bool
    {
        $norm = mb_strtolower(trim($text));
        if (in_array($norm, [
            'خطا در اعتبارسنجی',
            'خطای اعتبارسنجی',
            'خطا',
            'validation error',
            'validation failed',
            'the given data was invalid.',
            'the given data was invalid',
            'unprocessable entity',
            'unprocessable content',
        ], true)) {
            return true;
        }

        return mb_strlen($norm) < 40 && (bool) preg_match('/اعتبارسنجی|validation error|validation failed/iu', $norm);
    }

    private function technicalMessage(string $text): bool
    {
        return (bool) preg_match('/unauthenticated|unauthorized|server error|could not be found|the given data was invalid|sqlstate|exception|stack trace/i', $text);
    }

    private function fieldLabel(string $key): string
    {
        return match ($key) {
            'mobile' => 'شماره موبایل',
            'national_id', 'national_code' => 'کد ملی',
            'email', 'webservice_email' => 'ایمیل',
            'iban', 'sheba' => 'شبا',
            'backup_iban' => 'شبا پشتیبان',
            'postal_code' => 'کد پستی',
            'first_name', 'first_name_en' => 'نام',
            'last_name', 'last_name_en' => 'نام خانوادگی',
            'father_name_fa', 'father_name', 'father_name_en' => 'نام پدر',
            'birth_date' => 'تاریخ تولد',
            'gender' => 'جنسیت',
            'address' => 'نشانی',
            'state_id' => 'استان',
            'city_id' => 'شهر',
            'tax' => 'کد مالیاتی',
            'webservice_ip', 'server_ip' => 'IP سرور',
            'webservice_domain', 'domain' => 'دامنه',
            'webservice_domain_callback', 'callback' => 'آدرس بازگشت',
            'webservice_name' => 'نام فروشگاه',
            'webservice_name_en' => 'نام انگلیسی فروشگاه',
            'gateways_category_id', 'category_id' => 'دسته‌بندی درگاه',
            'selfi', 'selfie' => 'سلفی',
            'national_card_front' => 'روی کارت ملی',
            'national_card_back' => 'پشت کارت ملی',
            default => '',
        };
    }
}
