<?php

namespace App\Services\Authorization;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PermissionService
{
    public function can(User $user, string $slug, ?Role $activeRole = null): bool
    {
        if ($user->isSuperuser()) {
            return true;
        }

        $permission = Permission::query()->where('slug', $slug)->where('is_active', true)->first();
        if (! $permission) {
            return false;
        }

        $override = DB::table('user_permissions')
            ->where('user_id', $user->id)
            ->where('permission_id', $permission->id)
            ->first();

        if ($override) {
            return (bool) $override->allowed;
        }

        $roleIds = $activeRole
            ? [$activeRole->id]
            : $user->activeRoles()->pluck('roles.id')->all();

        if ($roleIds === []) {
            return false;
        }

        return DB::table('role_permissions')
            ->whereIn('role_id', $roleIds)
            ->where('permission_id', $permission->id)
            ->where('allowed', true)
            ->exists();
    }

    public function authorize(User $user, string $slug, ?Role $activeRole = null): void
    {
        if (! $this->can($user, $slug, $activeRole)) {
            abort(403, 'دسترسی مجاز نیست.');
        }
    }

    public function slugsFor(User $user, ?Role $activeRole = null): array
    {
        if ($user->isSuperuser()) {
            return Permission::query()->where('is_active', true)->orderBy('slug')->pluck('slug')->all();
        }

        $roleIds = $activeRole
            ? [$activeRole->id]
            : $user->activeRoles()->pluck('roles.id')->all();

        if ($roleIds === []) {
            return [];
        }

        $granted = DB::table('role_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->whereIn('role_permissions.role_id', $roleIds)
            ->where('role_permissions.allowed', true)
            ->where('permissions.is_active', true)
            ->pluck('permissions.slug');

        $overrides = DB::table('user_permissions')
            ->join('permissions', 'permissions.id', '=', 'user_permissions.permission_id')
            ->where('user_permissions.user_id', $user->id)
            ->where('permissions.is_active', true)
            ->get(['permissions.slug', 'user_permissions.allowed']);

        $set = $granted->flip()->all();
        foreach ($overrides as $row) {
            if ($row->allowed) {
                $set[$row->slug] = true;
            } else {
                unset($set[$row->slug]);
            }
        }

        return array_keys($set);
    }
}
