<?php

namespace App\Support;

use App\Models\PersonalAccessToken;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Authorization\PermissionService;
use App\Services\Goals\GoalBankService;

class AuthUserPresenter
{
    public static function make(User $user, ?Role $role, ?PersonalAccessToken $token = null): array
    {
        $user->loadMissing('roles');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'mobile' => $user->mobile,
            'email' => $user->email,
            'avatar_url' => $user->avatar_url,
            'is_superuser' => $user->isSuperuser(),
            'impersonating' => $token ? str_starts_with((string) $token->name, 'impersonation') : false,
            'roles' => $user->roles->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'slug' => $r->slug,
                'hierarchy_level' => $r->hierarchy_level,
                'is_organizational' => (bool) $r->is_organizational,
                'is_active' => (bool) $r->pivot->is_active,
            ])->values(),
            'active_role' => $role ? [
                'id' => $role->id,
                'name' => $role->name,
                'slug' => $role->slug,
            ] : null,
            'permissions' => app(PermissionService::class)->slugsFor($user, $role),
            'features' => [
                'shared_links' => SystemSetting::sharedLinkFeatures(),
                'product_oriented' => (bool) config('finopal.product_oriented', false),
                'goal_bank' => app(GoalBankService::class)->featureState($user, $role),
            ],
        ];
    }

    public static function dashboardPath(?string $roleSlug): string
    {
        return match ($roleSlug) {
            'superuser' => '/superuser',
            'representative' => '/dashboard/representative',
            'representative_referrer' => '/dashboard/representative-referrer',
            'sales_manager' => '/dashboard/sales-manager',
            'development_manager' => '/dashboard/development-manager',
            'senior_manager' => '/dashboard/senior-manager',
            default => '/dashboard/representative',
        };
    }
}
