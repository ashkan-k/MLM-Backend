<?php

namespace App\Services\User;

use App\Models\ReferralCode;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Organization\OrganizationTreeService;

class UserBlockService
{
    public function __construct(
        private readonly OrganizationTreeService $tree,
        private readonly AuditService $audit,
    ) {}

    public function canManage(User $actor, User $target): bool
    {
        if ($actor->id === $target->id) {
            return false;
        }
        if ($actor->isSuperuser()) {
            return true;
        }

        return $actor->hasRole('senior_manager') && $this->tree->isDescendant($actor, $target);
    }

    public function block(User $actor, User $target, string $reason = ''): User
    {
        $this->assertCanManage($actor, $target);
        if ($target->isSuperuser()) {
            abort(422, 'مسدود کردن مدیر سامانه مجاز نیست.');
        }

        $old = $target->only(['is_active']);
        $this->applyBlock($target);
        $this->audit->record($actor, 'user.blocked', $target, $old, [
            'is_active' => false,
            'reason' => $reason !== '' ? $reason : null,
        ]);

        return $target->fresh('roles');
    }

    public function unblock(User $actor, User $target): User
    {
        $this->assertCanManage($actor, $target);

        $old = $target->only(['is_active']);
        $target->update(['is_active' => true]);
        ReferralCode::query()->where('user_id', $target->id)->update(['is_active' => true]);
        $this->audit->record($actor, 'user.unblocked', $target, $old, ['is_active' => true]);

        return $target->fresh('roles');
    }

    public function applyBlock(User $target): void
    {
        $target->update(['is_active' => false]);
        $target->tokens()->delete();
        ReferralCode::query()->where('user_id', $target->id)->update(['is_active' => false]);
    }

    private function assertCanManage(User $actor, User $target): void
    {
        if (! $this->canManage($actor, $target)) {
            abort(403, 'فقط مدیر سامانه، یا مدیر ارشد برای زیرمجموعه خود، می‌تواند حساب را مسدود کند.');
        }
    }
}
