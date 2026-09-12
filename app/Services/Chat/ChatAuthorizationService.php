<?php

namespace App\Services\Chat;

use App\Models\Conversation;
use App\Models\User;
use App\Services\Authorization\PermissionService;
use App\Services\Organization\OrganizationTreeService;

class ChatAuthorizationService
{
    public function __construct(
        private readonly OrganizationTreeService $tree,
        private readonly PermissionService $permissions,
    ) {}

    public function canMessage(User $actor, User $target): bool
    {
        if ($actor->isSuperuser() || $target->isSuperuser()) {
            return true;
        }

        if ($this->tree->canCommunicate($actor, $target)) {
            return true;
        }

        return $this->permissions->can($actor, 'chat.cross_branch.message');
    }

    public function assertCanMessage(User $actor, User $target): void
    {
        if (! $this->canMessage($actor, $target)) {
            abort(403, 'ارسال پیام به این کاربر مجاز نیست.');
        }
    }

    public function assertParticipant(User $user, Conversation $conversation): void
    {
        $isMember = $conversation->participantRows()->where('user_id', $user->id)->whereNull('left_at')->exists();
        if (! $isMember && ! $user->isSuperuser()) {
            abort(403, 'شما عضو این گفتگو نیستید.');
        }
    }
}
