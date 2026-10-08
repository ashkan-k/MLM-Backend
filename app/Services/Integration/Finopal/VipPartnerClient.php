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
        $payload = ['postal_code' => $postalCode];
        foreach (['reference/postal-inquiry', 'inquiry/postal-code'] as $path) {
            try {
                return $this->postJson($path, $payload);
            } catch (VipPartnerException) {
                // Partner API v1.2 has no postal-inquiry route yet.
            }
        }

        throw new VipPartnerException('استعلام کد پستی در وب‌سرویس شریک فینوپال فعال نیست. استان، شهر و نشانی را دستی وارد کنید.');
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

    private function url(string $path): string
    {
        return rtrim((string) config('finopal.vip.base_url'), '/').'/api/vip/v1/'.ltrim($path, '/');
    }

    private function failureMessage(string $path, int $status, array $json): string
    {
        unset($path);
        $base = trim((string) ($json['message'] ?? $json['error'] ?? ''));
        if ($base !== '' && ! $this->technicalMessage($base)) {
            return $base;
        }

        $errors = $json['errors'] ?? data_get($json, 'data.errors');
        $lines = [];
        if (is_array($errors)) {
            foreach ($errors as $key => $messages) {
                $text = trim(is_array($messages) ? implode(' ', array_map('strval', $messages)) : (string) $messages);
                if ($text === '' || $this->technicalMessage($text)) {
                    continue;
                }
                $label = is_string($key) ? $this->fieldLabel($key) : '';
                $lines[] = ($label !== '' && ! str_contains($text, $label)) ? $label.' '.$text : $text;
            }
        }
        $lines = array_values(array_unique($lines));
        if ($lines !== []) {
            return implode('؛ ', array_slice($lines, 0, 4));
        }

        if ($status === 404) {
            return 'این سرویس در فینوپال پیدا نشد.';
        }

        return 'فینوپال این درخواست را نپذیرفت. اطلاعات را بررسی کنید و دوباره تلاش کنید.';
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
            default => '',
        };
    }
}
