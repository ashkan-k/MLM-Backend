<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FinopalWebhookJob;
use App\Services\Audit\AuditService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class QueueMonitorController extends Controller
{
    public function index(Request $request)
    {
        $this->ensureDatabaseQueueTables();

        $queueFilter = trim((string) $request->query('queue', ''));
        $q = trim((string) $request->query('q', ''));
        $pendingLimit = min(200, max(1, (int) $request->query('pending_limit', 100)));
        $failedLimit = min(200, max(1, (int) $request->query('failed_limit', 100)));

        $pendingQuery = DB::table('jobs')->orderByDesc('id');
        $failedQuery = DB::table('failed_jobs')->orderByDesc('id');

        if ($queueFilter !== '') {
            $pendingQuery->where('queue', $queueFilter);
            $failedQuery->where('queue', $queueFilter);
        }

        $pendingRows = $pendingQuery->limit($pendingLimit)->get();
        $failedRows = $failedQuery->limit($failedLimit)->get();

        $pending = $pendingRows
            ->map(fn ($row) => $this->presentPending($row))
            ->values()
            ->all();
        $failed = $failedRows
            ->map(fn ($row) => $this->presentFailed($row))
            ->values()
            ->all();

        if ($q !== '') {
            $needle = mb_strtolower($q);
            $pending = array_values(array_filter($pending, fn ($row) => $this->matchesNeedle($row, $needle)));
            $failed = array_values(array_filter($failed, fn ($row) => $this->matchesNeedle($row, $needle)));
        }

        $batches = [];
        if (Schema::hasTable('job_batches')) {
            $batches = DB::table('job_batches')
                ->orderByDesc('created_at')
                ->limit(50)
                ->get()
                ->map(fn ($row) => $this->presentBatch($row))
                ->values()
                ->all();
        }

        $now = time();
        $reserved = (int) DB::table('jobs')->whereNotNull('reserved_at')->count();
        $delayed = (int) DB::table('jobs')->where('available_at', '>', $now)->count();
        $waiting = (int) DB::table('jobs')
            ->whereNull('reserved_at')
            ->where('available_at', '<=', $now)
            ->count();

        $failedToday = (int) DB::table('failed_jobs')
            ->whereDate('failed_at', now()->toDateString())
            ->count();

        $queues = DB::table('jobs')
            ->select('queue', DB::raw('count(*) as total'))
            ->groupBy('queue')
            ->orderBy('queue')
            ->get()
            ->map(fn ($row) => [
                'queue' => (string) $row->queue,
                'total' => (int) $row->total,
            ])
            ->values()
            ->all();

        $webhooks = $this->webhookJobs($q, $queueFilter, min(200, max(1, (int) $request->query('webhook_limit', 100))));

        return response()->json([
            'connection' => (string) config('queue.default'),
            'driver' => (string) config('queue.connections.'.config('queue.default').'.driver', config('queue.default')),
            'table' => (string) config('queue.connections.database.table', 'jobs'),
            'retry_after' => (int) config('queue.connections.database.retry_after', 90),
            'stats' => [
                'pending' => (int) DB::table('jobs')->count(),
                'waiting' => $waiting,
                'reserved' => $reserved,
                'delayed' => $delayed,
                'failed' => (int) DB::table('failed_jobs')->count(),
                'failed_today' => $failedToday,
                'batches' => Schema::hasTable('job_batches') ? (int) DB::table('job_batches')->count() : 0,
                'webhooks' => $webhooks['stats']['total'],
                'webhooks_failed' => $webhooks['stats']['failed'],
                'webhooks_processing' => $webhooks['stats']['processing'],
            ],
            'queues' => $queues,
            'pending' => $pending,
            'failed' => $failed,
            'batches' => $batches,
            'webhooks' => $webhooks['rows'],
        ]);
    }

    public function showWebhook(string $webhook)
    {
        if (! Schema::hasTable('finopal_webhook_jobs')) {
            return response()->json(['message' => 'جدول لاگ وب‌هوک هنوز ساخته نشده است.'], 404);
        }

        $row = FinopalWebhookJob::query()
            ->where('uuid', $webhook)
            ->orWhere('id', is_numeric($webhook) ? (int) $webhook : 0)
            ->first();
        if (! $row) {
            return response()->json(['message' => 'لاگ وب‌هوک پیدا نشد.'], 404);
        }

        return response()->json(['job' => $this->presentWebhook($row, true)]);
    }

    public function showPending(int $job)
    {
        $this->ensureDatabaseQueueTables();
        $row = DB::table('jobs')->where('id', $job)->first();
        if (! $row) {
            return response()->json(['message' => 'جاب در صف پیدا نشد.'], 404);
        }

        return response()->json(['job' => $this->presentPending($row, true)]);
    }

    public function showFailed(string $failed)
    {
        $this->ensureDatabaseQueueTables();
        $row = DB::table('failed_jobs')
            ->where('uuid', $failed)
            ->orWhere('id', is_numeric($failed) ? (int) $failed : 0)
            ->first();
        if (! $row) {
            return response()->json(['message' => 'جاب ناموفق پیدا نشد.'], 404);
        }

        return response()->json(['job' => $this->presentFailed($row, true)]);
    }

    public function destroyPending(int $job, Request $request, AuditService $audit)
    {
        $this->ensureDatabaseQueueTables();
        $row = DB::table('jobs')->where('id', $job)->first();
        if (! $row) {
            return response()->json(['message' => 'جاب در صف پیدا نشد.'], 404);
        }

        DB::table('jobs')->where('id', $job)->delete();
        $audit->record($request->user(), 'queue.pending_deleted', 'queue_job', [
            'id' => $row->id,
            'queue' => $row->queue,
            'display_name' => $this->payloadMeta((string) $row->payload)['display_name'] ?? null,
        ], null);

        return response()->json(['success' => true, 'message' => 'جاب از صف حذف شد.']);
    }

    public function releasePending(int $job, Request $request, AuditService $audit)
    {
        $this->ensureDatabaseQueueTables();
        $row = DB::table('jobs')->where('id', $job)->first();
        if (! $row) {
            return response()->json(['message' => 'جاب در صف پیدا نشد.'], 404);
        }

        DB::table('jobs')->where('id', $job)->update([
            'reserved_at' => null,
            'attempts' => max(0, ((int) $row->attempts) - 1),
            'available_at' => time(),
        ]);

        $audit->record($request->user(), 'queue.pending_released', 'queue_job', null, [
            'id' => $row->id,
            'queue' => $row->queue,
        ]);

        return response()->json(['success' => true, 'message' => 'رزرو جاب آزاد شد و دوباره در صف قرار گرفت.']);
    }

    public function retryFailed(string $failed, Request $request, AuditService $audit)
    {
        $this->ensureDatabaseQueueTables();
        $row = DB::table('failed_jobs')
            ->where('uuid', $failed)
            ->orWhere('id', is_numeric($failed) ? (int) $failed : 0)
            ->first();
        if (! $row) {
            return response()->json(['message' => 'جاب ناموفق پیدا نشد.'], 404);
        }

        Artisan::call('queue:retry', ['id' => [(string) $row->uuid]]);
        $audit->record($request->user(), 'queue.failed_retried', 'queue_job', null, [
            'uuid' => $row->uuid,
            'queue' => $row->queue,
            'display_name' => $this->payloadMeta((string) $row->payload)['display_name'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'جاب برای اجرای مجدد به صف برگشت.',
            'artisan' => trim(Artisan::output()),
        ]);
    }

    public function destroyFailed(string $failed, Request $request, AuditService $audit)
    {
        $this->ensureDatabaseQueueTables();
        $row = DB::table('failed_jobs')
            ->where('uuid', $failed)
            ->orWhere('id', is_numeric($failed) ? (int) $failed : 0)
            ->first();
        if (! $row) {
            return response()->json(['message' => 'جاب ناموفق پیدا نشد.'], 404);
        }

        DB::table('failed_jobs')->where('id', $row->id)->delete();
        $audit->record($request->user(), 'queue.failed_deleted', 'queue_job', [
            'uuid' => $row->uuid,
            'queue' => $row->queue,
        ], null);

        return response()->json(['success' => true, 'message' => 'جاب ناموفق حذف شد.']);
    }

    public function retryAllFailed(Request $request, AuditService $audit)
    {
        $this->ensureDatabaseQueueTables();
        $count = (int) DB::table('failed_jobs')->count();
        Artisan::call('queue:retry', ['id' => ['all']]);
        $audit->record($request->user(), 'queue.failed_retry_all', 'queue_job', null, [
            'count' => $count,
        ]);

        return response()->json([
            'success' => true,
            'message' => $count > 0 ? "{$count} جاب ناموفق برای اجرا به صف برگشت." : 'جاب ناموفقی برای retry نبود.',
            'count' => $count,
            'artisan' => trim(Artisan::output()),
        ]);
    }

    public function flushFailed(Request $request, AuditService $audit)
    {
        $this->ensureDatabaseQueueTables();
        $count = (int) DB::table('failed_jobs')->count();
        Artisan::call('queue:flush');
        $audit->record($request->user(), 'queue.failed_flushed', 'queue_job', ['count' => $count], null);

        return response()->json([
            'success' => true,
            'message' => $count > 0 ? "{$count} جاب ناموفق پاک شد." : 'لیست جاب‌های ناموفق خالی بود.',
            'count' => $count,
        ]);
    }

    private function ensureDatabaseQueueTables(): void
    {
        if (! Schema::hasTable('jobs') || ! Schema::hasTable('failed_jobs')) {
            throw new RuntimeException('جداول صف (jobs/failed_jobs) روی این دیتابیس موجود نیست.');
        }
    }

    /**
     * @return array{rows: list<array<string, mixed>>, stats: array{total: int, failed: int, processing: int, completed: int}}
     */
    private function webhookJobs(string $q, string $queueFilter, int $limit): array
    {
        if (! Schema::hasTable('finopal_webhook_jobs')) {
            return [
                'rows' => [],
                'stats' => ['total' => 0, 'failed' => 0, 'processing' => 0, 'completed' => 0],
            ];
        }

        $query = FinopalWebhookJob::query()->orderByDesc('id');
        if ($queueFilter !== '' && $queueFilter !== 'finopal-webhook') {
            // Keep empty when filtering a laravel queue name that is not webhooks.
            if (! str_starts_with($queueFilter, 'finopal')) {
                return [
                    'rows' => [],
                    'stats' => [
                        'total' => (int) FinopalWebhookJob::query()->count(),
                        'failed' => (int) FinopalWebhookJob::query()->where('success', false)->count(),
                        'processing' => (int) FinopalWebhookJob::query()->where('status', 'processing')->count(),
                        'completed' => (int) FinopalWebhookJob::query()->where('status', 'completed')->count(),
                    ],
                ];
            }
            $query->where('queue', $queueFilter);
        }

        $rows = $query->limit($limit)->get()
            ->map(fn (FinopalWebhookJob $row) => $this->presentWebhook($row))
            ->values()
            ->all();

        if ($q !== '') {
            $needle = mb_strtolower($q);
            $rows = array_values(array_filter($rows, fn ($row) => $this->matchesNeedle($row, $needle)));
        }

        return [
            'rows' => $rows,
            'stats' => [
                'total' => (int) FinopalWebhookJob::query()->count(),
                'failed' => (int) FinopalWebhookJob::query()->where(function ($q) {
                    $q->where('success', false)->orWhere('status', 'failed');
                })->count(),
                'processing' => (int) FinopalWebhookJob::query()->where('status', 'processing')->count(),
                'completed' => (int) FinopalWebhookJob::query()->where('status', 'completed')->count(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentWebhook(FinopalWebhookJob $row, bool $detailed = false): array
    {
        $base = [
            'id' => (int) $row->id,
            'uuid' => (string) $row->uuid,
            'endpoint' => (string) $row->endpoint,
            'queue' => (string) $row->queue,
            'status' => (string) $row->status,
            'http_status' => $row->http_status,
            'success' => $row->success,
            'display_name' => 'finopal.webhook.'.$row->endpoint,
            'national_id' => $row->national_id,
            'mobile' => $row->mobile,
            'referral_code' => $row->referral_code,
            'idempotency_key' => $row->idempotency_key,
            'message' => $row->message,
            'exception_short' => $row->exception ? mb_substr(trim(strtok((string) $row->exception, "\n") ?: (string) $row->exception), 0, 400) : null,
            'duration_ms' => $row->duration_ms,
            'started_at' => optional($row->started_at)?->toIso8601String(),
            'finished_at' => optional($row->finished_at)?->toIso8601String(),
            'created_at' => optional($row->created_at)?->toIso8601String(),
        ];

        if ($detailed) {
            $base['request_summary'] = $row->request_summary;
            $base['response_summary'] = $row->response_summary;
            $base['errors'] = $row->errors;
            $base['exception'] = $row->exception;
            $base['ip_address'] = $row->ip_address;
            $base['user_agent'] = $row->user_agent;
        }

        return $base;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentPending(object $row, bool $detailed = false): array
    {
        $meta = $this->payloadMeta((string) $row->payload);
        $reservedAt = $row->reserved_at ? Carbon::createFromTimestamp((int) $row->reserved_at) : null;
        $availableAt = Carbon::createFromTimestamp((int) $row->available_at);
        $createdAt = Carbon::createFromTimestamp((int) $row->created_at);
        $now = now();

        $status = 'waiting';
        if ($reservedAt) {
            $status = 'reserved';
        } elseif ($availableAt->greaterThan($now)) {
            $status = 'delayed';
        }

        $base = [
            'id' => (int) $row->id,
            'queue' => (string) $row->queue,
            'attempts' => (int) $row->attempts,
            'status' => $status,
            'display_name' => $meta['display_name'],
            'job_uuid' => $meta['uuid'],
            'max_tries' => $meta['max_tries'],
            'timeout' => $meta['timeout'],
            'command_name' => $meta['command_name'],
            'reserved_at' => $reservedAt?->toIso8601String(),
            'available_at' => $availableAt->toIso8601String(),
            'created_at' => $createdAt->toIso8601String(),
        ];

        if ($detailed) {
            $base['payload_preview'] = $meta['payload_preview'];
            $base['payload_keys'] = $meta['payload_keys'];
        }

        return $base;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentFailed(object $row, bool $detailed = false): array
    {
        $meta = $this->payloadMeta((string) $row->payload);
        $failedAt = $row->failed_at
            ? Carbon::parse($row->failed_at)
            : null;

        $exception = (string) ($row->exception ?? '');
        $exceptionShort = trim(strtok($exception, "\n") ?: $exception);

        $base = [
            'id' => (int) $row->id,
            'uuid' => (string) $row->uuid,
            'connection' => (string) $row->connection,
            'queue' => (string) $row->queue,
            'display_name' => $meta['display_name'],
            'job_uuid' => $meta['uuid'],
            'max_tries' => $meta['max_tries'],
            'timeout' => $meta['timeout'],
            'command_name' => $meta['command_name'],
            'exception_short' => mb_substr($exceptionShort, 0, 400),
            'failed_at' => $failedAt?->toIso8601String(),
        ];

        if ($detailed) {
            $base['exception'] = mb_substr($exception, 0, 20000);
            $base['payload_preview'] = $meta['payload_preview'];
            $base['payload_keys'] = $meta['payload_keys'];
        }

        return $base;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentBatch(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'name' => (string) $row->name,
            'total_jobs' => (int) $row->total_jobs,
            'pending_jobs' => (int) $row->pending_jobs,
            'failed_jobs' => (int) $row->failed_jobs,
            'cancelled_at' => $row->cancelled_at
                ? Carbon::createFromTimestamp((int) $row->cancelled_at)->toIso8601String()
                : null,
            'created_at' => Carbon::createFromTimestamp((int) $row->created_at)->toIso8601String(),
            'finished_at' => $row->finished_at
                ? Carbon::createFromTimestamp((int) $row->finished_at)->toIso8601String()
                : null,
        ];
    }

    /**
     * @return array{
     *   display_name: ?string,
     *   uuid: ?string,
     *   max_tries: int|null,
     *   timeout: int|null,
     *   command_name: ?string,
     *   payload_keys: list<string>,
     *   payload_preview: array<string, mixed>
     * }
     */
    private function payloadMeta(string $payload): array
    {
        $decoded = json_decode($payload, true);
        if (! is_array($decoded)) {
            return [
                'display_name' => null,
                'uuid' => null,
                'max_tries' => null,
                'timeout' => null,
                'command_name' => null,
                'payload_keys' => [],
                'payload_preview' => ['_raw' => mb_substr($payload, 0, 500)],
            ];
        }

        $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
        $preview = [
            'displayName' => $decoded['displayName'] ?? null,
            'job' => $decoded['job'] ?? null,
            'maxTries' => $decoded['maxTries'] ?? null,
            'timeout' => $decoded['timeout'] ?? null,
            'backoff' => $decoded['backoff'] ?? null,
            'retryUntil' => $decoded['retryUntil'] ?? null,
            'data' => [
                'commandName' => $data['commandName'] ?? null,
                // Never expose full serialized PHP command blob in the UI by default.
                'command_bytes' => isset($data['command']) ? strlen((string) $data['command']) : null,
            ],
        ];

        return [
            'display_name' => isset($decoded['displayName']) ? (string) $decoded['displayName'] : null,
            'uuid' => isset($decoded['uuid']) ? (string) $decoded['uuid'] : null,
            'max_tries' => isset($decoded['maxTries']) ? (int) $decoded['maxTries'] : null,
            'timeout' => isset($decoded['timeout']) ? (int) $decoded['timeout'] : null,
            'command_name' => isset($data['commandName']) ? (string) $data['commandName'] : null,
            'payload_keys' => array_values(array_keys($decoded)),
            'payload_preview' => $preview,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function matchesNeedle(array $row, string $needle): bool
    {
        $hay = mb_strtolower(implode(' ', [
            $row['id'] ?? '',
            $row['uuid'] ?? '',
            $row['queue'] ?? '',
            $row['display_name'] ?? '',
            $row['command_name'] ?? '',
            $row['exception_short'] ?? '',
            $row['status'] ?? '',
            $row['job_uuid'] ?? '',
            $row['endpoint'] ?? '',
            $row['message'] ?? '',
            $row['national_id'] ?? '',
            $row['mobile'] ?? '',
            $row['referral_code'] ?? '',
        ]));

        return str_contains($hay, $needle);
    }
}
