<?php

namespace Tests\Feature;

use App\Models\FinopalWebhookJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class QueueMonitorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_superuser_can_monitor_retry_and_delete_queue_jobs(): void
    {
        $token = $this->postJson('/api/auth/login', [
            'mobile' => '09120000000',
            'password' => 'Password123!',
            'role_slug' => 'superuser',
        ])->assertOk()->json('token');

        $now = time();
        $pendingId = DB::table('jobs')->insertGetId([
            'queue' => 'default',
            'payload' => json_encode([
                'uuid' => (string) Str::uuid(),
                'displayName' => 'App\\Jobs\\DemoPendingJob',
                'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
                'maxTries' => 3,
                'timeout' => 60,
                'data' => ['commandName' => 'App\\Jobs\\DemoPendingJob', 'command' => 'O:1:"x":0:{}'],
            ], JSON_UNESCAPED_SLASHES),
            'attempts' => 1,
            'reserved_at' => $now,
            'available_at' => $now,
            'created_at' => $now - 10,
        ]);

        $failedUuid = (string) Str::uuid();
        DB::table('failed_jobs')->insert([
            'uuid' => $failedUuid,
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode([
                'uuid' => $failedUuid,
                'displayName' => 'App\\Jobs\\DemoFailedJob',
                'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
                'data' => ['commandName' => 'App\\Jobs\\DemoFailedJob', 'command' => 'O:1:"y":0:{}'],
            ], JSON_UNESCAPED_SLASHES),
            'exception' => "RuntimeException: boom\n#0 /tmp/demo.php(1)",
            'failed_at' => now(),
        ]);

        FinopalWebhookJob::query()->create([
            'uuid' => (string) Str::uuid(),
            'endpoint' => 'signup',
            'queue' => 'finopal-webhook',
            'status' => 'failed',
            'http_status' => 422,
            'success' => false,
            'national_id' => '1111222333',
            'mobile' => '09121110000',
            'message' => 'این کاربر قبلاً ثبت شده است.',
            'duration_ms' => 42,
            'started_at' => now()->subSeconds(2),
            'finished_at' => now(),
        ]);

        $index = $this->withToken($token)->getJson('/api/superuser/queues')->assertOk();
        $index->assertJsonPath('stats.pending', 1)
            ->assertJsonPath('stats.failed', 1)
            ->assertJsonPath('stats.reserved', 1)
            ->assertJsonPath('stats.webhooks', 1)
            ->assertJsonPath('pending.0.display_name', 'App\\Jobs\\DemoPendingJob')
            ->assertJsonPath('pending.0.status', 'reserved')
            ->assertJsonPath('failed.0.display_name', 'App\\Jobs\\DemoFailedJob')
            ->assertJsonPath('webhooks.0.endpoint', 'signup')
            ->assertJsonPath('webhooks.0.success', false);

        $this->withToken($token)
            ->getJson('/api/superuser/queues/pending/'.$pendingId)
            ->assertOk()
            ->assertJsonPath('job.id', $pendingId)
            ->assertJsonPath('job.payload_preview.displayName', 'App\\Jobs\\DemoPendingJob');

        $this->withToken($token)
            ->postJson('/api/superuser/queues/pending/'.$pendingId.'/release')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertNull(DB::table('jobs')->where('id', $pendingId)->value('reserved_at'));

        $this->withToken($token)
            ->getJson('/api/superuser/queues/failed/'.$failedUuid)
            ->assertOk()
            ->assertJsonPath('job.uuid', $failedUuid);

        $this->withToken($token)
            ->postJson('/api/superuser/queues/failed/'.$failedUuid.'/retry')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(0, DB::table('failed_jobs')->where('uuid', $failedUuid)->count());
        $this->assertGreaterThanOrEqual(1, DB::table('jobs')->count());

        $this->withToken($token)
            ->deleteJson('/api/superuser/queues/pending/'.$pendingId)
            ->assertOk()
            ->assertJsonPath('success', true);

        // Insert another failed job and flush.
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\FlushMe'], JSON_UNESCAPED_SLASHES),
            'exception' => 'fail',
            'failed_at' => now(),
        ]);

        $this->withToken($token)
            ->deleteJson('/api/superuser/queues/failed')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_representative_cannot_access_queue_monitor(): void
    {
        $token = $this->postJson('/api/auth/login', [
            'mobile' => '09125555555',
            'password' => 'Password123!',
            'role_slug' => 'representative',
        ])->assertOk()->json('token');

        $this->withToken($token)->getJson('/api/superuser/queues')->assertForbidden();
    }
}
