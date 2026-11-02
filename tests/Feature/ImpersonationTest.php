<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PersonalAccessToken;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImpersonationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_superuser_can_impersonate_user_with_chosen_role(): void
    {
        $super = User::query()->where('mobile', '09120000000')->firstOrFail();
        $target = User::query()->where('mobile', '09125555555')->firstOrFail();
        $repRole = Role::query()->where('slug', 'representative')->firstOrFail();
        $salesRole = Role::query()->where('slug', 'sales_manager')->firstOrFail();

        UserRole::query()->firstOrCreate(
            ['user_id' => $target->id, 'role_id' => $salesRole->id],
            ['effective_from' => now()->toDateString(), 'is_active' => true]
        );

        [, $superToken] = $super->createApiToken(Role::query()->where('slug', 'superuser')->value('id'));

        $this->withToken($superToken)
            ->postJson("/api/superuser/users/{$target->id}/impersonate", [
                'role_slug' => 'sales_manager',
            ])
            ->assertOk()
            ->assertJsonPath('user.id', $target->id)
            ->assertJsonPath('user.active_role.slug', 'sales_manager')
            ->assertJsonPath('user.impersonating', true)
            ->assertJsonPath('redirect_path', '/dashboard/sales-manager')
            ->assertJsonStructure(['token']);

        $impersonationToken = PersonalAccessToken::query()
            ->where('user_id', $target->id)
            ->where('name', 'impersonation:'.$super->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($impersonationToken);
        $this->assertSame($salesRole->id, (int) $impersonationToken->active_role_id);
        $this->assertNotNull($impersonationToken->expires_at);

        $this->assertTrue(
            AuditLog::query()
                ->where('action', 'user.impersonated')
                ->where('actor_user_id', $super->id)
                ->where('auditable_id', $target->id)
                ->exists()
        );

        // Non-superuser cannot impersonate.
        [, $repToken] = $target->createApiToken($repRole->id);
        $this->withToken($repToken)
            ->postJson("/api/superuser/users/{$super->id}/impersonate", [
                'role_slug' => 'superuser',
            ])
            ->assertForbidden();
    }

    public function test_impersonate_rejects_inactive_user_and_invalid_role(): void
    {
        $super = User::query()->where('mobile', '09120000000')->firstOrFail();
        $target = User::query()->where('mobile', '09125555555')->firstOrFail();
        [, $superToken] = $super->createApiToken(Role::query()->where('slug', 'superuser')->value('id'));

        $this->withToken($superToken)
            ->postJson("/api/superuser/users/{$target->id}/impersonate", [
                'role_slug' => 'senior_manager',
            ])
            ->assertStatus(422);

        $target->update(['is_active' => false]);

        $this->withToken($superToken)
            ->postJson("/api/superuser/users/{$target->id}/impersonate", [
                'role_slug' => 'representative',
            ])
            ->assertStatus(422);
    }

    public function test_impersonation_token_works_for_me_and_does_not_notify_target_via_audit_actor(): void
    {
        $super = User::query()->where('mobile', '09120000000')->firstOrFail();
        $target = User::query()->where('mobile', '09125555555')->firstOrFail();
        [, $superToken] = $super->createApiToken(Role::query()->where('slug', 'superuser')->value('id'));

        $res = $this->withToken($superToken)
            ->postJson("/api/superuser/users/{$target->id}/impersonate", [
                'role_slug' => 'representative',
            ])
            ->assertOk();

        $plain = $res->json('token');

        $this->withToken($plain)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('id', $target->id)
            ->assertJsonPath('impersonating', true)
            ->assertJsonPath('active_role.slug', 'representative');

        // Target user still has no "login" audit as actor — only superuser is the actor.
        $this->assertFalse(
            AuditLog::query()
                ->where('action', 'user.impersonated')
                ->where('actor_user_id', $target->id)
                ->exists()
        );
    }
}
