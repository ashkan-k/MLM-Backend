<?php

namespace App\Support;

use App\Models\Permission;
use App\Models\Role;

class PermissionCatalog
{
    /** @return array<string, array{name: string, roles: '*'|list<string>}> */
    public static function pagePermissions(): array
    {
        return [
            'page.dashboard' => ['name' => 'صفحه داشبورد', 'roles' => '*'],
            'page.team' => ['name' => 'صفحه شبکه و تیم', 'roles' => '*'],
            'page.gateways' => ['name' => 'صفحه درگاه‌ها', 'roles' => '*'],
            'page.commissions' => ['name' => 'صفحه پورسانت', 'roles' => '*'],
            'page.wallet' => ['name' => 'صفحه کیف پول', 'roles' => '*'],
            'page.finance' => ['name' => 'صفحه گزارش تجمیعی', 'roles' => '*'],
            'page.withdrawals' => ['name' => 'صفحه برداشت', 'roles' => '*'],
            'page.transfers' => ['name' => 'صفحه انتقال مالکیت مزایا', 'roles' => ['senior_manager']],
            'page.referrals' => ['name' => 'صفحه لینک معرف و اشتراکی', 'roles' => '*'],
            'page.promotions' => ['name' => 'صفحه ارتقاء سمت', 'roles' => '*'],
            'page.training' => ['name' => 'صفحه آموزش', 'roles' => '*'],
            'page.courses_manage' => ['name' => 'مدیریت دوره‌ها و سطوح', 'roles' => ['senior_manager']],
            'page.chat' => ['name' => 'صفحه گفتگو', 'roles' => '*'],
            'page.notifications' => ['name' => 'صفحه اعلان‌ها', 'roles' => '*'],
        ];
    }

    /** @return array<string, array{name: string, roles: '*'|list<string>}> */
    public static function actionPermissions(): array
    {
        return [
            'senior_manager.gateway.inspect' => ['name' => 'مدیر ارشد / تایید درگاه و ثبت کد مرچنت فاینوپال', 'roles' => ['senior_manager']],
        ];
    }

    public static function sync(): void
    {
        $roles = Role::query()->get()->keyBy('slug');
        $catalog = array_merge(self::pagePermissions(), self::actionPermissions());

        foreach ($catalog as $slug => $meta) {
            $parts = explode('.', $slug);
            $permission = Permission::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $meta['name'],
                    'panel' => $parts[0] ?? 'page',
                    'module' => $parts[1] ?? 'page',
                    'action' => $parts[2] ?? 'view',
                    'is_active' => true,
                ]
            );

            foreach ($roles as $role) {
                $already = $role->permissions()->where('permissions.id', $permission->id)->exists();
                $allowed = $role->slug === 'superuser'
                    || $meta['roles'] === '*'
                    || (is_array($meta['roles']) && in_array($role->slug, $meta['roles'], true));

                if ($allowed && ! $already) {
                    $role->permissions()->attach($permission->id, ['allowed' => true]);
                } elseif (! $allowed && $already && $role->slug !== 'superuser') {
                    $role->permissions()->detach($permission->id);
                }
            }
        }
    }
}
