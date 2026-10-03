<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SmsDebugPanelTest extends TestCase
{
    use RefreshDatabase;

    private string $logPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        $this->logPath = storage_path('logs/sms-test-'.uniqid('', true).'.log');
        config(['sms.log_path' => $this->logPath, 'sms.driver' => 'log']);
    }

    protected function tearDown(): void
    {
        if (is_file($this->logPath)) {
            @unlink($this->logPath);
        }

        parent::tearDown();
    }

    public function test_superuser_can_list_test_and_clear_sms_debug_logs(): void
    {
        $token = $this->postJson('/api/auth/login', [
            'mobile' => '09120000000',
            'password' => 'Password123!',
            'role_slug' => 'superuser',
        ])->assertOk()->json('token');

        $this->withToken($token)
            ->postJson('/api/superuser/sms/test', [
                'phone' => '09121112233',
                'message' => 'تست پنل دیباگ',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $index = $this->withToken($token)->getJson('/api/superuser/sms')->assertOk();
        $index->assertJsonPath('stats.total', 1)
            ->assertJsonPath('stats.success', 1)
            ->assertJsonPath('logs.0.phone', '09121112233')
            ->assertJsonPath('logs.0.type', 'dashboard_test')
            ->assertJsonPath('logs.0.message', 'تست پنل دیباگ');

        $this->assertTrue(File::exists($this->logPath));
        $this->assertNotSame('', trim((string) File::get($this->logPath)));

        $this->withToken($token)
            ->deleteJson('/api/superuser/sms/logs')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->withToken($token)
            ->getJson('/api/superuser/sms')
            ->assertOk()
            ->assertJsonPath('stats.total', 0);
    }

    public function test_representative_cannot_access_sms_debug(): void
    {
        $token = $this->postJson('/api/auth/login', [
            'mobile' => '09125555555',
            'password' => 'Password123!',
            'role_slug' => 'representative',
        ])->assertOk()->json('token');

        $this->withToken($token)->getJson('/api/superuser/sms')->assertForbidden();
        $this->withToken($token)->postJson('/api/superuser/sms/test', [
            'phone' => '09121112233',
        ])->assertForbidden();
    }
}
