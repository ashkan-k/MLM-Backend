<?php

namespace Tests\Feature;

use App\Models\ReferralCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class ReferralShareSmsTest extends TestCase
{
    use RefreshDatabase;

    private string $logPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->logPath = storage_path('logs/sms-share-'.uniqid('', true).'.log');
        config([
            'sms.log_path' => $this->logPath,
            'sms.driver' => 'log',
            'sms.share_link_template' => "دعوت به سازمان فروش فینونت\nلینک شما:\n{link}",
            'sms.share_link_max_per_hour' => 3,
        ]);
        $userId = User::query()->where('mobile', '09125555555')->value('id');
        if ($userId) {
            RateLimiter::clear('share-sms:'.$userId);
        }
    }

    protected function tearDown(): void
    {
        if (is_file($this->logPath)) {
            @unlink($this->logPath);
        }
        parent::tearDown();
    }

    public function test_representative_can_sms_own_referral_link(): void
    {
        $token = $this->loginAs('09125555555', 'representative');
        $code = ReferralCode::query()->where('user_id', User::query()->where('mobile', '09125555555')->value('id'))->value('code');
        $this->assertNotEmpty($code);

        $link = 'https://finonet.ir/register?ref='.$code;

        $res = $this->withToken($token)->postJson('/api/referrals/share-sms', [
            'mobile' => '09396988720',
            'link' => $link,
            'kind' => 'register',
        ]);

        // driver=log → ثبت می‌شود ولی ارسال واقعی نیست
        $res->assertStatus(422)
            ->assertJsonPath('logged_only', true)
            ->assertJsonPath('success', false);

        $this->assertTrue(File::exists($this->logPath));
        $raw = (string) File::get($this->logPath);
        $this->assertStringContainsString('09396988720', $raw);
        $this->assertStringContainsString($link, $raw);
        $this->assertStringContainsString('share_link', $raw);
        $this->assertStringContainsString('دعوت به سازمان فروش فینونت', $raw);
    }

    public function test_http_provider_marks_real_sms_success(): void
    {
        config([
            'sms.driver' => 'http',
            'sms.text_providers_order' => 'http',
            'sms.from' => '20006000632',
            'sms.providers.http.enabled' => true,
            'sms.providers.http.url' => 'https://sms.test/send',
            'sms.providers.http.token' => '',
        ]);

        \Illuminate\Support\Facades\Http::fake([
            'sms.test/*' => \Illuminate\Support\Facades\Http::response(['return' => ['status' => 200]], 200),
        ]);

        $token = $this->loginAs('09125555555', 'representative');
        $code = ReferralCode::query()->where('user_id', User::query()->where('mobile', '09125555555')->value('id'))->value('code');

        $this->withToken($token)->postJson('/api/referrals/share-sms', [
            'mobile' => '09396988720',
            'link' => 'https://finonet.ir/register?ref='.$code,
            'kind' => 'register',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('logged_only', false)
            ->assertJsonPath('provider_key', 'http');
    }

    public function test_rejects_foreign_link(): void
    {
        $token = $this->loginAs('09125555555', 'representative');

        $this->withToken($token)->postJson('/api/referrals/share-sms', [
            'mobile' => '09396988720',
            'link' => 'https://finonet.ir/register?ref=NOTMINE99',
            'kind' => 'register',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['link']);
    }

    public function test_rejects_invalid_mobile(): void
    {
        $token = $this->loginAs('09125555555', 'representative');
        $code = ReferralCode::query()->where('user_id', User::query()->where('mobile', '09125555555')->value('id'))->value('code');

        $this->withToken($token)->postJson('/api/referrals/share-sms', [
            'mobile' => '12345',
            'link' => 'https://finonet.ir/register?ref='.$code,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['mobile']);
    }

    public function test_rate_limit_share_sms(): void
    {
        $token = $this->loginAs('09125555555', 'representative');
        $userId = User::query()->where('mobile', '09125555555')->value('id');
        RateLimiter::clear('share-sms:'.$userId);

        $code = ReferralCode::query()->where('user_id', $userId)->value('code');
        $payload = [
            'mobile' => '09396988720',
            'link' => 'https://finonet.ir/register?ref='.$code,
            'kind' => 'register',
        ];

        // با driver=log پاسخ 422 است ولی برای محدودیت نرخ، hit فقط روی success واقعی است.
        // پس برای تست rate-limit موقتاً http ساختگی می‌گذاریم.
        config([
            'sms.driver' => 'http',
            'sms.text_providers_order' => 'http',
            'sms.providers.http.enabled' => true,
            'sms.providers.http.url' => 'https://sms.test/send',
        ]);
        \Illuminate\Support\Facades\Http::fake([
            'sms.test/*' => \Illuminate\Support\Facades\Http::response('ok', 200),
        ]);

        $this->withToken($token)->postJson('/api/referrals/share-sms', $payload)->assertOk();
        $this->withToken($token)->postJson('/api/referrals/share-sms', $payload)->assertOk();
        $this->withToken($token)->postJson('/api/referrals/share-sms', $payload)->assertOk();
        $this->withToken($token)->postJson('/api/referrals/share-sms', $payload)
            ->assertStatus(429)
            ->assertJsonPath('reason', 'rate_limited');
    }

    private function loginAs(string $mobile, string $role): string
    {
        return $this->postJson('/api/auth/login', [
            'mobile' => $mobile,
            'password' => 'Password123!',
            'role_slug' => $role,
        ])->assertOk()->json('token');
    }
}
