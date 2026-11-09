<?php

namespace App\Services\Integration\Finopal;

use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Persist Finopal webhook outcomes into superuser audit_logs.
 */
class FinopalWebhookAuditor
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * @param  array<string, mixed>  $meta
     */
    public function success(string $action, ?User $user, array $meta = []): void
    {
        $this->safeRecord($action, $user ?? 'finopal_webhook', null, $meta);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function failure(string $action, array $meta = [], ?User $user = null): void
    {
        $this->safeRecord($action, $user ?? 'finopal_webhook', null, $meta);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @param  array<string, mixed>  $extra
     */
    public function fromResponse(string $endpoint, Request $request, Response $response, ?array $payload = null, array $extra = []): void
    {
        $status = $response->getStatusCode();
        $body = null;
        try {
            $content = $response->getContent();
            $decoded = is_string($content) ? json_decode($content, true) : null;
            $body = is_array($decoded) ? $decoded : null;
        } catch (Throwable) {
            $body = null;
        }

        $ok = $status >= 200 && $status < 300 && (($body['success'] ?? true) !== false);
        $action = $ok ? "finopal.webhook.{$endpoint}.ok" : "finopal.webhook.{$endpoint}.failed";

        $userId = $body['user_id'] ?? null;
        $user = is_numeric($userId) ? User::query()->find((int) $userId) : null;

        $this->safeRecord($action, $user ?? 'finopal_webhook', $this->safeRequestSummary($request, $payload), array_filter([
            'http_status' => $status,
            'success' => $ok,
            'message' => $body['message'] ?? null,
            'user_created' => $body['user_created'] ?? null,
            'created_count' => $body['created_count'] ?? null,
            'error_count' => $body['error_count'] ?? null,
            'errors' => isset($body['errors']) ? $this->truncate($body['errors']) : null,
            'idempotency_key' => $request->input('idempotency_key'),
            'referral_code' => $this->shortRef((string) $request->input('referral_code', '')),
            'national_id' => $this->digits((string) data_get($payload ?? $request->all(), 'user.national_id', data_get($payload ?? $request->all(), 'MLM_Structure.REPS.NationalCode', ''))),
            'mobile' => $this->digits((string) data_get($payload ?? $request->all(), 'user.mobile', '')),
            'webhook_job_id' => $extra['webhook_job_id'] ?? null,
            'webhook_job_uuid' => $extra['webhook_job_uuid'] ?? null,
            'duration_ms' => $extra['duration_ms'] ?? null,
            'exception' => $extra['exception'] ?? null,
        ], static fn ($v) => $v !== null && $v !== ''));
    }

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    private function safeRecord(string $action, User|string $auditable, ?array $old, ?array $new): void
    {
        try {
            $this->audit->record(null, $action, $auditable, $old, $new);
        } catch (Throwable) {
            // Never break webhook flow because of audit persistence.
        }
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>
     */
    private function safeRequestSummary(Request $request, ?array $payload): array
    {
        $data = $payload ?? $request->all();
        unset($data['webhook_secret'], $data['password']);

        return [
            'path' => '/'.$request->path(),
            'event' => $data['event'] ?? null,
            'keys' => array_values(array_keys($data)),
        ];
    }

    private function shortRef(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (strlen($value) > 80) {
            return substr($value, 0, 77).'...';
        }

        return $value;
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

    private function truncate(mixed $value): mixed
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE);
        if ($json === false || strlen($json) <= 2000) {
            return $value;
        }

        return ['_truncated' => true, 'preview' => substr($json, 0, 1800)];
    }
}
