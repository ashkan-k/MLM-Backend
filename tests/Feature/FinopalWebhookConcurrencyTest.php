<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ReferralCode;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Services\Organization\OrganizationTreeService;
use App\Support\UniqueConstraint;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class FinopalWebhookConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_five_distinct_signups_all_create_and_are_audited(): void
    {
        $this->seedReferrer('REF-CONC-1');

        $created = [];
        for ($i = 1; $i <= 5; $i++) {
            $nid = sprintf('90000000%02d', $i);
            $mobile = sprintf('09120001%03d', $i);
            $res = $this->postJson('/api/webhooks/finopal/signup', [
                'event' => 'user.registered',
                'referral_code' => 'REF-CONC-1',
                'user' => [
                    'name' => "کاربر همزمان {$i}",
                    'mobile' => $mobile,
                    'national_id' => $nid,
                ],
            ], [
                'X-Finopal-Webhook-Secret' => 'test-webhook-secret',
            ])->assertOk()
                ->assertJsonPath('success', true)
                ->assertJsonPath('user_created', true);

            $created[] = (int) $res->json('user_id');
        }

        $this->assertCount(5, array_unique($created));
        $this->assertSame(5, User::query()->whereIn('id', $created)->count());
        $this->assertSame(5, User::query()->where('national_id', 'like', '90000000%')->count());
        $this->assertSame(5, AuditLog::query()->where('action', 'finopal.webhook.signup.ok')->count());
    }

    public function test_burst_same_identity_creates_only_one_user_and_audits_failures(): void
    {
        $this->seedReferrer('REF-CONC-SAME');
        Cache::setDefaultDriver('file');

        $payload = [
            'event' => 'user.registered',
            'referral_code' => 'REF-CONC-SAME',
            'user' => [
                'name' => 'کاربر تکراری همزمان',
                'mobile' => '09121112233',
                'national_id' => '8111222333',
            ],
        ];

        $statuses = [];
        for ($i = 0; $i < 8; $i++) {
            $statuses[] = $this->postJson('/api/webhooks/finopal/signup', $payload, [
                'X-Finopal-Webhook-Secret' => 'test-webhook-secret',
            ])->status();
        }

        $this->assertSame(1, User::query()->where('national_id', '8111222333')->count());
        $this->assertSame(1, collect($statuses)->filter(fn ($s) => $s === 200)->count());
        $this->assertTrue(collect($statuses)->contains(422));
        $this->assertGreaterThanOrEqual(1, AuditLog::query()->where('action', 'finopal.webhook.signup.failed')->count());
        $this->assertGreaterThanOrEqual(1, AuditLog::query()->where('action', 'finopal.webhook.signup.ok')->count());
    }

    public function test_referral_code_unique_is_not_misclassified_as_user_already_registered(): void
    {
        ReferralCode::query()->create([
            'user_id' => User::factory()->create()->id,
            'code' => '77777',
            'source' => 'test',
            'is_active' => true,
        ]);

        try {
            ReferralCode::query()->create([
                'user_id' => User::factory()->create()->id,
                'code' => '77777',
                'source' => 'test',
                'is_active' => true,
            ]);
            $this->fail('Expected unique violation');
        } catch (QueryException $e) {
            $this->assertTrue(UniqueConstraint::violated($e));
            $this->assertFalse(UniqueConstraint::violatedOn($e, [
                'users_national_id_unique',
                'users_mobile_unique',
                'users_farasof_user_id_unique',
                'users.national_id',
                'users.mobile',
                'users.farasof_user_id',
            ]));
        }
    }

    public function test_failed_signup_is_audited(): void
    {
        $this->postJson('/api/webhooks/finopal/signup', [
            'event' => 'user.registered',
            'referral_code' => 'MISSING-REF',
            'user' => [
                'name' => 'بدون معرف',
                'mobile' => '09129990001',
                'national_id' => '8222333444',
            ],
        ], [
            'X-Finopal-Webhook-Secret' => 'test-webhook-secret',
        ])->assertStatus(422);

        $this->assertSame(1, AuditLog::query()->where('action', 'finopal.webhook.signup.failed')->count());
        $this->assertSame(0, User::query()->where('national_id', '8222333444')->count());
    }

    private function seedReferrer(string $code): User
    {
        $repRole = $this->role('representative', 'نماینده', 5);
        $smRole = $this->role('sales_manager', 'مدیر فروش', 3);
        $this->role('representative_referrer', 'نماینده معرف', 4);

        $manager = User::factory()->create(['name' => 'مدیر', 'mobile' => '09120000091']);
        $referrer = User::factory()->create(['name' => 'معرف همزمان', 'mobile' => '09120000092']);
        UserRole::query()->create([
            'user_id' => $manager->id,
            'role_id' => $smRole->id,
            'effective_from' => now()->toDateString(),
            'is_primary' => true,
            'is_active' => true,
        ]);
        UserRole::query()->create([
            'user_id' => $referrer->id,
            'role_id' => $repRole->id,
            'effective_from' => now()->toDateString(),
            'is_primary' => true,
            'is_active' => true,
        ]);

        $tree = app(OrganizationTreeService::class);
        $smNode = $tree->attach($manager, $smRole, null, now()->toDateString());
        $tree->attach($referrer, $repRole, $smNode, now()->toDateString());

        ReferralCode::query()->create([
            'user_id' => $referrer->id,
            'code' => $code,
            'source' => 'finopal',
            'is_active' => true,
        ]);

        return $referrer;
    }

    private function role(string $slug, string $name, int $level): Role
    {
        return Role::query()->create([
            'name' => $name,
            'slug' => $slug,
            'hierarchy_level' => $level,
            'is_organizational' => true,
            'is_active' => true,
        ]);
    }
}
