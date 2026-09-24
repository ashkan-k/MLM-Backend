<?php

namespace Tests\Feature;

use App\Models\ReferralCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * QA Phases 5–6: registration / referral edge cases.
 */
class QaRegistrationReferralTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_valid_referral_registration_creates_user(): void
    {
        $code = ReferralCode::query()
            ->where('user_id', User::query()->where('mobile', '09125555555')->value('id'))
            ->value('code');

        $this->postJson('/api/auth/register', [
            'name' => 'QA New Rep',
            'mobile' => '09131234567',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'referral_code' => $code,
        ])->assertSuccessful();

        $this->assertDatabaseHas('users', ['mobile' => '09131234567']);
    }

    public function test_duplicate_mobile_rejected(): void
    {
        $code = ReferralCode::query()->where('is_active', true)->value('code');
        $this->postJson('/api/auth/register', [
            'name' => 'Dup',
            'mobile' => '09125555555',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'referral_code' => $code,
        ])->assertStatus(422);
    }

    public function test_weak_password_rejected(): void
    {
        $code = ReferralCode::query()->where('is_active', true)->value('code');
        $this->postJson('/api/auth/register', [
            'name' => 'Weak',
            'mobile' => '09131234568',
            'password' => '123',
            'password_confirmation' => '123',
            'referral_code' => $code,
        ])->assertStatus(422);
    }

    public function test_inactive_referrer_code_rejected(): void
    {
        $codeRow = ReferralCode::query()->where('is_active', true)->firstOrFail();
        $codeRow->update(['is_active' => false]);

        $this->postJson('/api/auth/register', [
            'name' => 'Inactive Ref',
            'mobile' => '09131234569',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'referral_code' => $codeRow->code,
        ])->assertStatus(422);
    }
}
