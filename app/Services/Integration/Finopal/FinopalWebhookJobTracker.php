<?php

namespace App\Services\Integration\Finopal;

use App\Models\FinopalWebhookJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class FinopalWebhookJobTracker
{
    private static bool $ensured = false;

    public function start(string $endpoint, Request $request, ?array $payload = null): ?FinopalWebhookJob
    {
        $this->ensureTable();

        try {
            $data = $payload ?? $request->all();
            unset($data['webhook_secret'], $data['password']);

            return FinopalWebhookJob::query()->create([
                'uuid' => (string) Str::uuid(),
                'endpoint' => $endpoint,
                'queue' => 'finopal-webhook',
                'status' => 'processing',
                'national_id' => $this->digits((string) data_get($data, 'user.national_id', '')),
                'mobile' => $this->digits((string) data_get($data, 'user.mobile', '')),
                'referral_code' => $this->short((string) ($data['referral_code'] ?? ''), 120),
                'idempotency_key' => $this->short((string) ($data['idempotency_key'] ?? ''), 190),
                'request_summary' => [
                    'path' => '/'.$request->path(),
                    'event' => $data['event'] ?? null,
                    'keys' => array_values(array_keys($data)),
                    'has_mlm_structure' => isset($data['MLM_Structure']) || isset($data['mlm_structure']),
                ],
                'ip_address' => $request->ip(),
                'user_agent' => $this->short((string) $request->userAgent(), 255),
                'started_at' => now(),
            ]);
        } catch (Throwable) {
            return null;
        }
    }

    public function finish(?FinopalWebhookJob $job, Response $response, int $durationMs): void
    {
        if (! $job) {
            return;
        }

        try {
            $body = null;
            $content = $response->getContent();
            $decoded = is_string($content) ? json_decode($content, true) : null;
            if (is_array($decoded)) {
                $body = $decoded;
            }

            $status = $response->getStatusCode();
            $ok = $status >= 200 && $status < 300 && (($body['success'] ?? true) !== false);

            $job->fill([
                'status' => $ok ? 'completed' : 'failed',
                'http_status' => $status,
                'success' => $ok,
                'message' => isset($body['message']) ? $this->short((string) $body['message'], 500) : null,
                'national_id' => $job->national_id ?: $this->digits((string) data_get($body, 'national_id', '')),
                'mobile' => $job->mobile ?: $this->digits((string) data_get($body, 'mobile', '')),
                'response_summary' => [
                    'success' => $body['success'] ?? $ok,
                    'user_id' => $body['user_id'] ?? null,
                    'user_created' => $body['user_created'] ?? null,
                    'created_count' => $body['created_count'] ?? null,
                    'error_count' => $body['error_count'] ?? null,
                    'gateway_created' => $body['gateway_created'] ?? null,
                    'duplicate' => $body['duplicate'] ?? null,
                ],
                'errors' => isset($body['errors']) ? $this->truncateArray($body['errors']) : null,
                'duration_ms' => max(0, $durationMs),
                'finished_at' => now(),
            ])->save();
        } catch (Throwable) {
            // never break webhook
        }
    }

    public function fail(?FinopalWebhookJob $job, Throwable $e, int $durationMs, ?int $httpStatus = 500): void
    {
        if (! $job) {
            return;
        }

        try {
            $job->fill([
                'status' => 'failed',
                'http_status' => $httpStatus,
                'success' => false,
                'message' => $this->short($e->getMessage(), 500),
                'exception' => $this->short(class_basename($e).': '.$e->getMessage()."\n".$e->getTraceAsString(), 20000),
                'duration_ms' => max(0, $durationMs),
                'finished_at' => now(),
            ])->save();
        } catch (Throwable) {
            // ignore
        }
    }

    private function ensureTable(): void
    {
        if (self::$ensured) {
            return;
        }
        self::$ensured = true;

        if (Schema::hasTable('finopal_webhook_jobs')) {
            return;
        }

        try {
            Schema::create('finopal_webhook_jobs', function ($table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('endpoint', 40)->index();
                $table->string('queue', 64)->default('finopal-webhook')->index();
                $table->string('status', 24)->default('processing')->index();
                $table->unsignedSmallInteger('http_status')->nullable();
                $table->boolean('success')->nullable()->index();
                $table->string('national_id', 20)->nullable()->index();
                $table->string('mobile', 20)->nullable()->index();
                $table->string('referral_code', 120)->nullable();
                $table->string('idempotency_key', 190)->nullable()->index();
                $table->string('message', 500)->nullable();
                $table->json('request_summary')->nullable();
                $table->json('response_summary')->nullable();
                $table->json('errors')->nullable();
                $table->text('exception')->nullable();
                $table->unsignedInteger('duration_ms')->nullable();
                $table->string('ip_address', 64)->nullable();
                $table->string('user_agent', 255)->nullable();
                $table->timestamp('started_at')->nullable()->index();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();
            });
        } catch (Throwable) {
            // race with parallel first requests
        }
    }

    private function digits(string $value): string
    {
        $map = [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ];

        return preg_replace('/\D+/', '', strtr($value, $map)) ?? '';
    }

    private function short(string $value, int $max): string
    {
        $value = trim($value);
        if (mb_strlen($value) <= $max) {
            return $value;
        }

        return mb_substr($value, 0, $max - 3).'...';
    }

    private function truncateArray(mixed $value): mixed
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE);
        if ($json === false || strlen($json) <= 4000) {
            return $value;
        }

        return ['_truncated' => true, 'preview' => substr($json, 0, 3500)];
    }
}
